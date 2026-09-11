<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Agent;
use App\Models\Expense;
use App\Models\Office;
use App\Models\Remittance;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'office' => Office::class,
            'agent' => Agent::class,
            'account' => Account::class,
            'remittance' => Remittance::class,
            'expense' => Expense::class,
            'transfer' => Transfer::class,
        ]);
    }
}
