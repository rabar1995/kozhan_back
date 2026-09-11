<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Remittance;
use App\Models\Views\VAgentBalance;
use App\Models\Views\VRemittance;
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

    /**
     * Commission data from the v_remittances view.
     */
    public function commissionSummary(Request $request): JsonResponse
    {
        $rows = VRemittance::query()
            ->select(['remittance_number', 'direction', 'status', 'commission_amount', 'commission_currency', 'commission_type', 'agent_name', 'created_at'])
            ->where('office_id', $request->user()->office_id)
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->orderByDesc('created_at')
            ->get();

        return $this->ok($rows);
    }

    /**
     * Agent debtor/creditor balances from the v_agent_balances view.
     */
    public function agentBalances(Request $request): JsonResponse
    {
        $rows = VAgentBalance::query()
            ->where('office_id', $request->user()->office_id)
            ->orderByDesc('absolute_balance')
            ->get();

        return $this->ok($rows);
    }

    /**
     * Remittances aggregated by day, direction and currency.
     */
    public function dailySummary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $rows = Remittance::query()
            ->selectRaw("DATE(created_at) AS day, direction, send_currency_id, COUNT(*) AS remittances, SUM(send_amount) AS total_send, SUM(commission_amount) AS total_commission")
            ->where('office_id', $request->user()->office_id)
            ->whereNot('status', 'cancelled')
            ->when($data['start_date'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', date_create($d)->setTime(0, 0)))
            ->when($data['end_date'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', date_create($d)->setTime(23, 59, 59)))
            ->groupByRaw('DATE(created_at), direction, send_currency_id')
            ->orderByRaw('DATE(created_at) DESC')
            ->get();

        return $this->ok($rows);
    }
}
