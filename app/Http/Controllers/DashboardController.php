<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * Real-time KPIs from the fn_dashboard_kpis() PostgreSQL function,
     * plus per-currency breakdowns of every money figure. The scalar
     * money totals from fn_dashboard_kpis() add different currencies
     * together, so the UI shows the `*_by_currency` lists instead.
     */
    public function kpis(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $officeId = $request->user()->office_id;
        $date = $data['date'] ?? now()->toDateString();

        $row = DB::select('SELECT fn_dashboard_kpis(?, ?) AS kpis', [$officeId, $date]);
        $kpis = json_decode($row[0]->kpis, true);

        $kpis['today']['commission_earned_by_currency'] = $this->commissionByCurrency($officeId, $date, 'earned');
        $kpis['today']['commission_paid_by_currency'] = $this->commissionByCurrency($officeId, $date, 'paid');

        [$receivable, $payable] = $this->agentBalancesByCurrency($officeId);
        $kpis['agents']['receivable_by_currency'] = $receivable;
        $kpis['agents']['payable_by_currency'] = $payable;

        $kpis['monthly']['by_currency'] = $this->monthlyByCurrency($officeId, $date);

        return $this->ok($kpis);
    }

    /**
     * @return array<int, array{currency: string, amount: float}>
     */
    private function commissionByCurrency(string $officeId, string $date, string $type): array
    {
        return DB::table('remittances as r')
            ->join('currencies as c', 'c.id', '=', 'r.commission_currency_id')
            ->where('r.office_id', $officeId)
            ->where('r.commission_type', $type)
            ->where('r.status', '!=', 'cancelled')
            ->whereDate('r.created_at', $date)
            ->groupBy('c.code')
            ->orderBy('c.code')
            ->selectRaw('c.code AS currency, SUM(r.commission_amount) AS amount')
            ->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'amount' => (float) $r->amount])
            ->filter(fn ($r) => abs($r['amount']) >= 0.0001)
            ->values()
            ->all();
    }

    /**
     * Agent wallet balances per currency: positive = the agent owes us,
     * negative = we owe the agent.
     *
     * @return array{0: array<int, array{currency: string, amount: float}>, 1: array<int, array{currency: string, amount: float}>}
     */
    private function agentBalancesByCurrency(string $officeId): array
    {
        $rows = DB::table('agent_currency_accounts as aca')
            ->join('agents as ag', 'ag.id', '=', 'aca.agent_id')
            ->join('accounts as a', 'a.id', '=', 'aca.account_id')
            ->join('currencies as c', 'c.id', '=', 'a.currency_id')
            ->where('ag.office_id', $officeId)
            ->where('ag.is_active', true)
            ->whereNull('a.deleted_at')
            ->groupBy('c.code')
            ->orderBy('c.code')
            ->selectRaw('c.code AS currency,
                SUM(CASE WHEN a.current_balance > 0 THEN a.current_balance ELSE 0 END) AS receivable,
                SUM(CASE WHEN a.current_balance < 0 THEN -a.current_balance ELSE 0 END) AS payable')
            ->get();

        $pick = fn (string $field) => $rows
            ->map(fn ($r) => ['currency' => $r->currency, 'amount' => (float) $r->{$field}])
            ->filter(fn ($r) => $r['amount'] >= 0.0001)
            ->values()
            ->all();

        return [$pick('receivable'), $pick('payable')];
    }

    /**
     * Revenue, expenses and net income for the month of $date, per currency.
     *
     * @return array<int, array{currency: string, revenue: float, expenses: float, net: float}>
     */
    private function monthlyByCurrency(string $officeId, string $date): array
    {
        $start = Carbon::parse($date)->startOfMonth();
        $end = $start->copy()->addMonth();

        return DB::table('journal_entries as je')
            ->join('accounts as a', 'a.id', '=', 'je.account_id')
            ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
            ->join('transactions as t', 't.id', '=', 'je.transaction_id')
            ->join('currencies as c', 'c.id', '=', 'a.currency_id')
            ->where('a.office_id', $officeId)
            ->whereIn('at.category', ['revenue', 'expense'])
            ->where('t.is_void', false)
            ->where('je.created_at', '>=', $start)
            ->where('je.created_at', '<', $end)
            ->groupBy('c.code')
            ->orderBy('c.code')
            ->selectRaw("c.code AS currency,
                SUM(CASE WHEN at.category = 'revenue'
                    THEN CASE WHEN je.entry_type = 'credit' THEN je.amount ELSE -je.amount END ELSE 0 END) AS revenue,
                SUM(CASE WHEN at.category = 'expense'
                    THEN CASE WHEN je.entry_type = 'debit' THEN je.amount ELSE -je.amount END ELSE 0 END) AS expenses")
            ->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'revenue' => (float) $r->revenue,
                'expenses' => (float) $r->expenses,
                'net' => round((float) $r->revenue - (float) $r->expenses, 4),
            ])
            ->all();
    }
}
