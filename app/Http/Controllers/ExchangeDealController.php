<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Account;
use App\Models\ExchangeDeal;
use App\Services\ExchangeDealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeDealController extends Controller
{
    use ApiResponse;

    public function __construct(private ExchangeDealService $deals)
    {
    }

    /**
     * The owner's private wallets (from Wallets & Safes) the deals
     * move money between.
     */
    public function formOptions(Request $request): JsonResponse
    {
        $officeId = $request->user()->office_id;

        return $this->ok([
            'wallets' => Account::withoutGlobalScopes()
                ->with('currency:id,code,symbol')
                ->where('office_id', $officeId)
                ->where('visibility', 'owner_private')
                ->where('is_active', true)
                ->withoutSystemTypes()
                ->orderBy('name')
                ->get(['id', 'name', 'currency_id', 'logo_url'])
                ->map(fn (Account $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'currency_id' => $a->currency_id,
                    'currency_code' => $a->currency?->code,
                    'logo_url' => $a->logo_url,
                ]),
        ]);
    }

    /**
     * Owner dashboard: pending account balances and open deals.
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->ok($this->deals->summary($request->user()->office_id));
    }

    /**
     * Exchange deal history with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $officeId = $request->user()->office_id;

        $rows = ExchangeDeal::query()
            ->with([
                'currency:id,code,symbol',
                'settleCurrency:id,code,symbol',
                'account:id,name',
                'settleAccount:id,name',
                'dealParent:id,tx_number,counterparty_name',
                'dealSettlements:id,deal_parent_id,tx_number,settle_amount,settle_currency_id,settle_account_id,created_at' => [
                    'settleCurrency:id,code,symbol',
                    'settleAccount:id,name',
                ],
                'creator:id,name',
            ])
            ->where('office_id', $officeId)
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->string('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->ok($rows);
    }

    /**
     * Book a two-sided deal in one card:
     *  - send side:  wallet + amount the money leaves
     *  - receive side: wallet + amount the money arrives in
     *  - status:  "completed" (done now: both legs booked atomically)
     *             or "pending" (send leg booked, receive side saved as plan)
     */
    public function openDeal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'send_account_id' => ['required', 'uuid', 'exists:accounts,id'],
            'send_amount' => ['required', 'numeric', 'min:0.01'],
            'receive_account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'receive_amount' => ['nullable', 'numeric', 'min:0.01'],
            'status' => ['required', 'in:pending,completed'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($data['status'] === 'completed' && (empty($data['receive_account_id']) || empty($data['receive_amount']))) {
            return $this->fail('A completed deal needs the receiving wallet and amount.', 422);
        }

        try {
            $record = $this->deals->openDeal($data, $request->user());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($record->load(['currency:id,code,symbol', 'account:id,name']), $record->tx_number.' booked.', 201);
    }

    /**
     * Close an open deal: the money came back / was paid out in another
     * wallet. Profit/loss is computed automatically from the two amounts.
     */
    public function settleDeal(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'settle_account_id' => ['required', 'uuid', 'exists:accounts,id'],
            'settle_amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $record = $this->deals->settleDeal($id, $data, $request->user());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($record->load([
            'currency:id,code,symbol', 'settleCurrency:id,code,symbol',
            'account:id,name', 'settleAccount:id,name', 'dealParent:id,tx_number',
        ]), $record->tx_number.' settled.', 201);
    }

    /**
     * Cancel a deal leg (owner only, reversal entries).
     * Cancelling a settle leg re-opens its deal.
     */
    public function cancel(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ]);

        try {
            $record = $this->deals->cancel($id, $data['reason'], $request->user());
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($record, $record->tx_number.' cancelled.');
    }
}
