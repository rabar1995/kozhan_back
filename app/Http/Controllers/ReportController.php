<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use ApiResponse;

    /**
     * Revenue vs Expense breakdown and net income for a date range,
     * straight from the fn_profit_loss() PostgreSQL function.
     */
    public function profitLoss(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $rows = DB::select(
            'SELECT * FROM fn_profit_loss(?, ?, ?)',
            [$request->user()->office_id, $data['start_date'], $data['end_date']]
        );

        return $this->ok($rows);
    }
}
