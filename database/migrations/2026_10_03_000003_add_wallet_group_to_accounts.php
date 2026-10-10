<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('wallet_group', 20)->nullable()->after('visibility');
        });

        // Backfill existing wallets into their logical groups.
        Account::withoutGlobalScopes()
            ->whereHas('accountType', fn ($q) => $q->where('code', 'agent_wallet'))
            ->update(['wallet_group' => 'agents']);

        Account::withoutGlobalScopes()
            ->where('visibility', 'office_shared')
            ->whereNull('wallet_group')
            ->update(['wallet_group' => 'office']);

        Account::withoutGlobalScopes()
            ->where('visibility', 'owner_private')
            ->whereNull('wallet_group')
            ->update(['wallet_group' => 'private']);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('wallet_group');
        });
    }
};
