<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Http\Requests\StoreRemittanceRequest;
use App\Models\Remittance;
use App\Services\RemittanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RemittanceController extends Controller
{
    use ApiResponse;

    public function __construct(private RemittanceService $remittances)
    {
    }

    /**
     * Filterable remittance list (status, direction, agent, date range).
     */
    public function index(Request $request): JsonResponse
    {
        $rows = Remittance::with(['agent:id,name', 'sendCurrency:id,code,symbol', 'receiveCurrency:id,code,symbol'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->string('direction')))
            ->when($request->filled('agent_id'), fn ($q) => $q->where('agent_id', $request->string('agent_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->ok($rows);
    }

    /**
     * Shortcut: the urgent pending payout queue.
     */
    public function pending(Request $request): JsonResponse
    {
        $rows = Remittance::pending()
            ->with(['agent:id,name', 'sendCurrency:id,code,symbol', 'receiveCurrency:id,code,symbol'])
            ->orderBy('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->ok($rows);
    }

    /**
     * Full remittance detail with linked transactions and journal entries.
     */
    public function show(string $id): JsonResponse
    {
        $remittance = Remittance::with([
            'agent', 'sendCurrency', 'receiveCurrency', 'commissionCurrency',
            'paymentAccount', 'createdBy:id,name', 'completedBy:id,name', 'cancelledBy:id,name',
            'bookingTransaction.journalEntries.account:id,name',
            'settlementTransaction.journalEntries.account:id,name',
            'commissionTransaction.journalEntries.account:id,name',
        ])->findOrFail($id);

        return $this->ok($remittance);
    }

    /**
     * Book an incoming remittance from an agent.
     */
    public function createIncoming(StoreRemittanceRequest $request): JsonResponse
    {
        $remittance = $this->remittances->createIncoming($request->validated(), $request->user());

        return $this->ok($remittance->load(['agent', 'sendCurrency', 'receiveCurrency']), 'Incoming remittance booked.', 201);
    }

    /**
     * Book an outgoing remittance for a walk-in client assigned to an agent.
     */
    public function createOutgoing(StoreRemittanceRequest $request): JsonResponse
    {
        $remittance = $this->remittances->createOutgoing($request->validated(), $request->user());

        return $this->ok($remittance->load(['agent', 'sendCurrency', 'receiveCurrency']), 'Outgoing remittance booked.', 201);
    }

    /**
     * Mark a pending remittance as completed (payout done).
     */
    public function complete(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_account_id' => ['required', 'uuid', 'exists:accounts,id'],
        ]);

        try {
            $remittance = $this->remittances->complete($id, $data['payment_account_id'], $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($remittance->load(['agent', 'sendCurrency', 'receiveCurrency']), 'Remittance completed.');
    }

    /**
     * Cancel a pending remittance (owner only) via reversal entries.
     */
    public function cancel(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $remittance = $this->remittances->cancel($id, $data['reason'], $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($remittance->load(['agent', 'sendCurrency', 'receiveCurrency']), 'Remittance cancelled.');
    }
}
