<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
use App\Models\Agent;
use App\Models\JournalEntry;
use App\Services\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AgentController extends Controller
{
    use ApiResponse;

    public function __construct(private AgentService $agents)
    {
    }

    /**
     * List agents with balances and debtor/creditor classification.
     */
    public function index(Request $request): JsonResponse
    {
        $agents = Agent::with(['currencyAccounts.receivableAccount:id,current_balance', 'currencyAccounts.payableAccount:id,current_balance', 'currencyAccounts.currency:id,code', 'balanceCurrency:id,code,symbol', 'receivableAccount:id,name,current_balance', 'payableAccount:id,name,current_balance'])
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return $this->ok($agents);
    }

    /**
     * Create an agent with its auto-generated receivable/payable accounts.
     */
    public function store(StoreAgentRequest $request): JsonResponse
    {
        $agent = $this->agents->create($request->validated(), $request->user());

        return $this->ok($agent->load(['balanceCurrency', 'receivableAccount', 'payableAccount']), 'Agent created.', 201);
    }

    /**
     * Update an agent's details and/or its logo URL.
     */
    public function update(string $id, UpdateAgentRequest $request): JsonResponse
    {
        $agent = Agent::where('is_active', true)
            ->where('office_id', $request->user()->office_id)
            ->findOrFail($id);

        $data = $request->validated();
        // currency_ids is not a column on agents — handled by updateCurrencies.
        $fillData = collect($data)->except(['currency_ids'])->all();

        if (array_key_exists('currency_ids', $data) || array_key_exists('allow_all_currencies', $data)) {
            DB::transaction(function () use ($agent, $data, $fillData) {
                $agent->fill($fillData)->save();
                $this->agents->updateCurrencies(
                    $agent,
                    $data['currency_ids'] ?? null,
                    (bool) ($data['allow_all_currencies'] ?? false),
                    $agent->office_id,
                );
            });
        } else {
            $agent->fill($fillData)->save();
        }

        return $this->ok(
            $agent->load(['currencyAccounts.receivableAccount', 'currencyAccounts.payableAccount', 'currencyAccounts.currency', 'balanceCurrency', 'receivableAccount', 'payableAccount']),
            'Agent updated.'
        );
    }

    /**
     * Show one agent.
     */
    public function show(string $id): JsonResponse
    {
        return $this->ok(Agent::with(['currencyAccounts.receivableAccount', 'currencyAccounts.payableAccount', 'currencyAccounts.currency', 'balanceCurrency', 'receivableAccount', 'payableAccount'])->findOrFail($id));
    }

    /**
     * Debtor/creditor status of an agent with account amounts.
     */
    public function balance(string $id): JsonResponse
    {
        $agent = Agent::with(['balanceCurrency:id,code,symbol', 'receivableAccount:id,name,current_balance', 'payableAccount:id,name,current_balance'])
            ->findOrFail($id);

        return $this->ok([
            'agent_id' => $agent->id,
            'name' => $agent->name,
            'classification' => $agent->classification,
            'net_balance' => $agent->net_balance,
            'currency' => $agent->balanceCurrency?->code,
            'receivable_balance' => $agent->receivableAccount?->current_balance,
            'payable_balance' => $agent->payableAccount?->current_balance,
            // Per-currency breakdown for multi-currency agents.
            'allow_all_currencies' => $agent->allow_all_currencies,
            'balances' => $agent->balances,
        ]);
    }

    /**
     * Transaction history across both agent accounts, with date filters.
     */
    public function ledger(string $id, Request $request): JsonResponse
    {
        $agent = Agent::findOrFail($id);

        $entries = JournalEntry::whereIn('account_id', [$agent->receivable_account_id, $agent->payable_account_id])
            ->with(['transaction:id,tx_number,tx_type,description,is_void,created_at', 'currency:id,code,symbol'])
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->orderBy('created_at')
            ->paginate($request->integer('per_page', 50));

        return $this->ok($entries);
    }
}
