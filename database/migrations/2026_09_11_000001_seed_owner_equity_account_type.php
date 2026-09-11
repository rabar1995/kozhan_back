<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cash Safes tab: opening balance support.
 *
 * Seeds the owner_equity account type used as the credit side of the
 * balanced "opening balance" transaction posted when a new safe is
 * created with a non-zero opening balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('account_types')->where('code', 'owner_equity')->exists();

        if ($exists) {
            return;
        }

        // Primary choice: equity. If the category column is constrained
        // (CHECK/enum without 'equity'), fall back to liability — owner
        // capital still posts as a credit-normal account either way.
        foreach (['equity', 'liability'] as $category) {
            try {
                $row = [
                    'code' => 'owner_equity',
                    'name' => "Owner's Equity / Opening Capital",
                    'category' => $category,
                ];

                if (DB::getSchemaBuilder()->hasColumn('account_types', 'normal_balance')) {
                    $row['normal_balance'] = 'credit';
                }

                if (DB::getSchemaBuilder()->hasColumn('account_types', 'is_system')) {
                    $row['is_system'] = true;
                }

                DB::table('account_types')->insert($row);

                return;
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    public function down(): void
    {
        DB::table('account_types')->where('code', 'owner_equity')->delete();
    }
};
