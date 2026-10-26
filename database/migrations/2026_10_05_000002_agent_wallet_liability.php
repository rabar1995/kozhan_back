<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reclassify agent_wallet from an asset to a liability so that
     * debit entries decrease the agent balance and credit entries
     * increase it, matching the hawala model where the wallet
     * represents money the office holds for / owes to the agent.
     */
    public function up(): void
    {
        DB::table('account_types')
            ->where('code', 'agent_wallet')
            ->update([
                'category' => 'liability',
                'normal_balance' => 'credit',
            ]);
    }

    public function down(): void
    {
        DB::table('account_types')
            ->where('code', 'agent_wallet')
            ->update([
                'category' => 'asset',
                'normal_balance' => 'debit',
            ]);
    }
};
