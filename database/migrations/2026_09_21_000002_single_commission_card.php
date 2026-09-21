<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One "Commission" card per currency.
 *
 * Replaces the separate commission_revenue / commission_expense account
 * types with a single credit-normal type (category revenue):
 *   - revenue credits add to the card
 *   - expense debits subtract
 * so `current_balance` is the net commission earned. `fn_profit_loss`
 * keeps working because the category remains 'revenue'.
 *
 * Mapping to the single type is done in RemittanceService /
 * ExchangeDealService; accounts of the new type are created lazily by
 * the existing findOfficeAccountByType() helper, so no account seeding
 * happens here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $typeId = DB::table('account_types')->where('code', 'commission')->value('id');

        if (! $typeId) {
            DB::table('account_types')->insert([
                'id' => (string) \Ramsey\Uuid\Uuid::uuid4(),
                'code' => 'commission',
                'name' => 'Commission',
                'category' => 'revenue',
                'normal_balance' => 'credit',
                'is_system' => true,
            ]);
        }

        // Dormant legacy 'Commission Expense' cards (no ledger history)
        // are removed so only the single Commission card surfaces.
        $deadType = DB::table('account_types')->where('code', 'commission_expense')->value('id');

        if ($deadType) {
            $inUse = DB::selectOne(
                'SELECT 1 FROM journal_entries je
                 JOIN accounts a ON a.id = je.account_id
                 WHERE a.account_type_id = ?
                 LIMIT 1',
                [$deadType]
            );

            if (! $inUse) {
                DB::table('accounts')->where('account_type_id', $deadType)->delete();
            }
        }
    }

    public function down(): void
    {
        // legacy types stay; nothing destructive
    }
};
