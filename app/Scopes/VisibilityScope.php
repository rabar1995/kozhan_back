<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class VisibilityScope implements Scope
{
    /**
     * Restrict account visibility: office managers only see office_shared accounts.
     * Owners see everything.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user && $user->role === 'office_manager') {
            $builder->where($model->getTable().'.visibility', 'office_shared');
        }
    }
}
