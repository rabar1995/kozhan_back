<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auto-seed expense master data so the "Register Expense" form has
 * selectable options:
 *
 * - Default expense categories (Rent, Salaries, ...) for every office
 *   that has none yet.
 * - One "Operating Expenses - CUR" and one "Commission Expense - CUR"
 *   account per (office, currency the office already operates in),
 *   mirroring the lazy "find by office + currency + type code, else
 *   create" pattern the rest of the system uses. The expense posting
 *   service requires paid-from wallet, expense account and currency to
 *   match, so accounts are created per currency.
 */
return new class extends Migration
{
    private const CATEGORIES = ['Rent', 'Salaries', 'Utilities', 'Office Supplies', 'Transport', 'Miscellaneous'];

    private const ACCOUNT_TYPES = [
        'operating_expense' => 'Operating Expenses',
        'commission' => 'Commission',
    ];

    public function up(): void
    {
        $offices = DB::table('offices')->pluck('id');

        foreach ($offices as $officeId) {
            $this->seedCategories($officeId);
            $this->seedExpenseAccounts($officeId);
        }
    }

    private function seedCategories(string $officeId): void
    {
        $existing = DB::table('expense_categories')->where('office_id', $officeId)->count();

        if ($existing > 0) {
            return;
        }

        $now = now();

        foreach (self::CATEGORIES as $name) {
            DB::table('expense_categories')->insert([
                'id' => (string) \Ramsey\Uuid\Uuid::uuid4(),
                'office_id' => $officeId,
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
            ]);
        }
    }

    private function seedExpenseAccounts(string $officeId): void
    {
        // Currencies the office actually operates in.
        $currencyIds = DB::table('accounts')
            ->where('office_id', $officeId)
            ->distinct()
            ->pluck('currency_id');

        foreach ($currencyIds as $currencyId) {
            $code = DB::table('currencies')->where('id', $currencyId)->value('code');

            foreach (self::ACCOUNT_TYPES as $typeCode => $namePrefix) {
                $typeId = DB::table('account_types')->where('code', $typeCode)->value('id');

                if (! $typeId) {
                    continue;
                }

                $exists = DB::table('accounts')
                    ->where('office_id', $officeId)
                    ->where('currency_id', $currencyId)
                    ->where('account_type_id', $typeId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('accounts')->insert([
                    'id' => (string) \Ramsey\Uuid\Uuid::uuid4(),
                    'office_id' => $officeId,
                    'account_type_id' => $typeId,
                    'currency_id' => $currencyId,
                    'name' => "{$namePrefix} - {$code}",
                    'visibility' => 'office_shared',
                    'current_balance' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Seeded reference data is never auto-deleted (references may
        // already exist in the ledger); document that in the log.
    }
};
