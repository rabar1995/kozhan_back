<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;

class CurrencyController extends Controller
{
    use ApiResponse;

    /**
     * List active currencies.
     */
    public function index(): JsonResponse
    {
        return $this->ok(Currency::where('is_active', true)->orderBy('code')->get());
    }
}
