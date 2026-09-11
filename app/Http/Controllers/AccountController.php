<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    use ApiResponse;

    /**
     * List accounts. The VisibilityScope automatically filters
     * owner_private accounts for office managers.
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = Account::with(['accountType', 'currency'])
            ->when($request->filled('type'), fn ($q) => $q->byType($request->string('type')))
            ->when($request->filled('currency_id'), fn ($q) => $q->byCurrency($request->string('currency_id')))
            ->when($request->filled('active'), fn ($q) => $q->active())
            ->orderBy('name')
            ->get();

        return $this->ok($accounts);
    }

    /**
     * Create a new wallet/account (owner only).
     * An optional opening_balance posts a balanced "opening balance"
     * transaction (debit the new account, credit the office's Owner's
     * Equity account) so the double-entry system stays intact.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_type_id' => ['required', 'uuid', 'exists:account_types,id'],
            'currency_id' => ['required', 'uuid', 'exists:currencies,id'],
            'name' => ['required', 'string', 'max:255'],
            'visibility' => ['required', 'in:owner_private,office_shared'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'logo_url' => ['nullable', 'url', 'max:255'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        $openingBalance = round((float) ($data['opening_balance'] ?? 0), 4);
        unset($data['opening_balance']);

        $user = $request->user();

        $account = DB::transaction(function () use ($data, $openingBalance, $user) {
            $account = Account::create([
                ...$data,
                'office_id' => $user->office_id,
                'current_balance' => 0,
            ]);

            if ($openingBalance > 0) {
                $equity = $this->ensureOwnerEquityAccount($user->office_id, $data['currency_id']);

                app(AccountingService::class)->createTransaction([
                    'office_id' => $user->office_id,
                    // 'adjustment' is one of the tx_type values allowed by
                    // the transactions_tx_type_check constraint in the DB.
                    'tx_type' => 'adjustment',
                    'description' => 'Opening balance for '.$account->name,
                    'created_by' => $user->id,
                    'reference_type' => 'account',
                    'reference_id' => $account->id,
                    'entries' => [
                        [
                            'account_id' => $account->id,
                            'entry_type' => 'debit',
                            'amount' => $openingBalance,
                            'currency_id' => $data['currency_id'],
                            'description' => 'Opening balance',
                        ],
                        [
                            'account_id' => $equity->id,
                            'entry_type' => 'credit',
                            'amount' => $openingBalance,
                            'currency_id' => $data['currency_id'],
                            'description' => 'Opening balance (owner capital)',
                        ],
                    ],
                ]);
            }

            return $account->fresh(['accountType', 'currency']);
        });

        return $this->ok($account, 'Account created.', 201);
    }

    /**
     * Find or lazily create the Owner's Equity account for the office
     * and currency used by opening-balance transactions.
     */
    private function ensureOwnerEquityAccount(string $officeId, string $currencyId): Account
    {
        $equity = Account::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('currency_id', $currencyId)
            ->where('visibility', 'owner_private')
            ->whereHas('accountType', fn ($q) => $q->where('code', 'owner_equity'))
            ->first();

        if ($equity) {
            return $equity;
        }

        $type = AccountType::where('code', 'owner_equity')->firstOrFail();

        return Account::create([
            'office_id' => $officeId,
            'account_type_id' => $type->id,
            'currency_id' => $currencyId,
            'name' => "Owner's Equity",
            'visibility' => 'owner_private',
            'current_balance' => 0,
        ]);
    }

    /**
     * Update an account/wallet (owner only).
     * Name, type and currency are editable; changing the currency is
     * blocked while the balance is not zero.
     */
    public function update(string $id, Request $request): JsonResponse
    {
        $account = Account::withoutGlobalScopes()->where('office_id', $request->user()->office_id)->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'account_type_id' => ['sometimes', 'uuid', 'exists:account_types,id'],
            'currency_id' => ['sometimes', 'uuid', 'exists:currencies,id'],
            'visibility' => ['sometimes', 'in:owner_private,office_shared'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'logo_url' => ['nullable', 'url', 'max:255'],
        ]);

        if (array_key_exists('currency_id', $data)
            && $data['currency_id'] !== $account->currency_id
            && round(abs((float) $account->current_balance), 4) > 0) {
            return $this->fail('The currency cannot be changed while the wallet balance is not zero.', 422);
        }

        $account->fill($data)->save();

        return $this->ok($account->load(['accountType', 'currency']), 'Account updated.');
    }

    /**
     * Show one account with its current balance.
     */
    public function show(string $id): JsonResponse
    {
        return $this->ok(Account::with(['accountType', 'currency'])->findOrFail($id));
    }

    /**
     * Journal entries (ledger) of an account, with optional date filters.
     */
    public function ledger(string $id, Request $request): JsonResponse
    {
        $account = Account::findOrFail($id);

        $entries = JournalEntry::where('account_id', $account->id)
            ->with(['transaction:id,tx_number,tx_type,description,is_void,created_at', 'currency:id,code,symbol'])
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->orderBy('created_at')
            ->paginate($request->integer('per_page', 50));

        return $this->ok($entries);
    }

    /**
     * Summary of all visible account balances grouped by account type.
     */
    public function balances(Request $request): JsonResponse
    {
        $accounts = Account::with(['accountType:id,code,name,category,normal_balance', 'currency:id,code,symbol'])
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->accountType?->code,
                'category' => $a->accountType?->category,
                'visibility' => $a->visibility,
                'currency' => $a->currency?->code,
                'symbol' => $a->currency?->symbol,
                'current_balance' => $a->current_balance,
            ]);

        return $this->ok($accounts);
    }
}
