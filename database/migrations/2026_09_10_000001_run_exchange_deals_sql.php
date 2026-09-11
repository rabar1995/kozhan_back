<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exchange Deals tab.
 *
 * The business schema of this project lives outside Laravel migrations
 * (see rest api prompt.txt): the PostgreSQL database is managed with raw
 * SQL scripts under database/sql. This migration applies the exchange
 * deals script (which replaces the earlier crypto tables) and seeds the
 * reference row it depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlFile = database_path('sql/exchange_deals.sql');

        if (is_file($sqlFile)) {
            DB::unprepared(file_get_contents($sqlFile));
        }

        $this->seedAccountType();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS exchange_deals CASCADE');
    }

    /**
     * Ensure the exchange_pending account type exists (asset, debit
     * normal balance). Existing rows are left untouched.
     */
    private function seedAccountType(): void
    {
        $exists = DB::table('account_types')->where('code', 'exchange_pending')->exists();

        if ($exists) {
            return;
        }

        $row = [
            'code' => 'exchange_pending',
            'name' => 'Exchange Deals Pending',
            'category' => 'asset',
        ];

        if (DB::getSchemaBuilder()->hasColumn('account_types', 'normal_balance')) {
            $row['normal_balance'] = 'debit';
        }

        if (DB::getSchemaBuilder()->hasColumn('account_types', 'is_system')) {
            $row['is_system'] = true;
        }

        DB::table('account_types')->insert($row);
    }
};
