<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\AccountType;
use Illuminate\Http\JsonResponse;

class AccountTypeController extends Controller
{
    use ApiResponse;

    /**
     * List all account types (for the account creation form).
     */
    public function index(): JsonResponse
    {
        return $this->ok(AccountType::orderBy('code')->get());
    }
}
