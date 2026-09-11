<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    use ApiResponse;

    /**
     * Latest exchange rates (optionally filtered by currency pair).
     */
    public function index(Request $request): JsonResponse
    {
        $rows = ExchangeRate::query()
            ->with(['fromCurrency:id,code,symbol', 'toCurrency:id,code,symbol', 'creator:id,name'])
            ->when($request->filled('from_currency_id'), fn ($q) => $q->where('from_currency_id', $request->string('from_currency_id')))
            ->when($request->filled('to_currency_id'), fn ($q) => $q->where('to_currency_id', $request->string('to_currency_id')))
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn ($rate) => $rate->from_currency_id.'->'.$rate->to_currency_id)
            ->values();

        return $this->ok($rows);
    }

    /**
     * Publish a new exchange rate.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_currency_id' => ['required', 'uuid', 'exists:currencies,id', 'different:to_currency_id'],
            'to_currency_id' => ['required', 'uuid', 'exists:currencies,id'],
            'buy_rate' => ['required', 'numeric', 'min:0.000001'],
            'sell_rate' => ['required', 'numeric', 'min:0.000001'],
        ]);

        $rate = ExchangeRate::create([
            ...$data,
            'office_id' => $request->user()->office_id,
            'created_by' => $request->user()->id,
        ]);

        return $this->ok($rate->load(['fromCurrency', 'toCurrency']), 'Exchange rate saved.', 201);
    }
}
