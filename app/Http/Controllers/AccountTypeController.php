<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\AccountType;
use Illuminate\Http\JsonResponse;

class AccountTypeController extends Controller
{
    use ApiResponse;

    /**
     * List account types (for the account creation form), excluding
     * the hidden system type (Owner's Equity).
     */
    public function index(): JsonResponse
    {
        return $this->ok(AccountType::where('code', '!=', 'owner_equity')->orderBy('code')->get());
    }
}
