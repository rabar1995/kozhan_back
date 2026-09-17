<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency agent balances.
 *
 * - agent_currency_accounts: one receivable+payable account pair
 *   per (agent, currency). Legacy agents.receivable_account_id /
 *   payable_account_id / balance_currency_id columns stay untouched
 *   for backward compatibility.
 * - agents.allow_all_currencies: when true, the resolver lazily
 *   creates pairs for currencies the agent has not been set up with.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_currency_accounts')) {
            Schema::create('agent_currency_accounts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_id');
                $table->uuid('currency_id');
                $table->uuid('receivable_account_id');
                $table->uuid('payable_account_id');
                $table->timestamps();

                $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
                $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('cascade');
                $table->foreign('receivable_account_id')->references('id')->on('accounts');
                $table->foreign('payable_account_id')->references('id')->on('accounts');
                $table->unique(['agent_id', 'currency_id']);
            });
        }

        if (! Schema::hasColumn('agents', 'allow_all_currencies')) {
            Schema::table('agents', function (Blueprint $t) {
                $t->boolean('allow_all_currencies')->default(false);
            });
        }

        // Seed: one pair of accounts per existing agent using its legacy
        // receivable/payable accounts and balance_currency_id.
        DB::statement("
            INSERT INTO agent_currency_accounts (id, agent_id, currency_id, receivable_account_id, payable_account_id, created_at, updated_at)
            SELECT gen_random_uuid(), a.id, a.balance_currency_id, a.receivable_account_id, a.payable_account_id, now(), now()
            FROM agents a
            WHERE a.balance_currency_id IS NOT NULL
              AND a.receivable_account_id IS NOT NULL
              AND a.payable_account_id IS NOT NULL
              AND NOT EXISTS (
                SELECT 1 FROM agent_currency_accounts aca
                WHERE aca.agent_id = a.id AND aca.currency_id = a.balance_currency_id
              )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_currency_accounts');

        if (Schema::hasColumn('agents', 'allow_all_currencies')) {
            Schema::table('agents', function (Blueprint $t) {
                $t->dropColumn('allow_all_currencies');
            });
        }
    }
};
