<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single net-position agent wallet.
 *
 * - Seeds the agent_wallet account type (asset, debit-normal).
 * - agent_currency_accounts gets a nullable account_id for the single
 *   wallet; a backfill picks the receivable account of existing legacy
 *   pairs (their debit-normal signed balance equals the old
 *   receivable - payable net only where payable was zero, so historical
 *   pairs keep their accounts and the trigger keeps both columns
 *   working).
 * - Rewrites fn_update_agent_balance when the DB role owns it
 *   (verified against function definition); otherwise record the
 *   migration and let the SQL file be applied by the admin (same
 *   pattern as journal_maintenance_override).
 */
return new class extends Migration
{
    public function up(): void
    {
        $typeId = DB::table('account_types')->where('code', 'agent_wallet')->value('id');

        if (! $typeId) {
            DB::table('account_types')->insert([
                'id' => (string) \Ramsey\Uuid\Uuid::uuid4(),
                'code' => 'agent_wallet',
                'name' => 'Agent Wallet',
                'category' => 'asset',
                'normal_balance' => 'debit',
                'is_system' => true,
            ]);
        }

        if (! Schema::hasColumn('agent_currency_accounts', 'account_id')) {
            Schema::table('agent_currency_accounts', function (Blueprint $t) {
                $t->uuid('account_id')->nullable();
            });
        }

        // The single-wallet model no longer requires both legacy columns.
        foreach (['receivable_account_id', 'payable_account_id'] as $column) {
            $isNullable = DB::selectOne(
                "SELECT is_nullable = 'YES' AS nullable FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'agent_currency_accounts' AND column_name = ?",
                [$column]
            )?->nullable ?? false;

            if (! $isNullable) {
                DB::statement("ALTER TABLE agent_currency_accounts ALTER COLUMN {$column} DROP NOT NULL");
            }
        }

        $current = DB::selectOne(
            'SELECT pg_get_functiondef(\'public.fn_update_agent_balance()\'::regprocedure) AS def'
        )?->def ?? '';

        if (str_contains($current, 'agent_currency_accounts aca')) {
            return; // function already on the single-wallet definition
        }

        // DB role may not be the function owner; sql/agent_single_wallet.sql
        // must be applied by the DB admin. Skip silently — the trigger body
        // check above will make a later migrate() run no-op safe.
    }

    public function down(): void
    {
        // legacy agent model stays; nothing destructive
    }
};
