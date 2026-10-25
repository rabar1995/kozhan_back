<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Restore office cash/bank/e-wallet accounts that were soft-deleted
     * so remittance payouts and collections can use them again.
     */
    public function up(): void
    {
        Account::onlyTrashed()
            ->where('wallet_group', 'office')
            ->whereHas('accountType', fn ($q) => $q->whereIn('code', ['cash_safe', 'e_wallet', 'bank_account']))
            ->get()
            ->each(function (Account $account) {
                $account->restore();
                $account->forceFill(['is_active' => true])->save();
            });
    }

    public function down(): void
    {
        // Restoration is a one-way data fix; no rollback needed.
    }
};
