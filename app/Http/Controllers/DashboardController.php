<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * Real-time KPIs from the fn_dashboard_kpis() PostgreSQL function.
     */
    public function kpis(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $row = DB::select(
            'SELECT fn_dashboard_kpis(?, ?) AS kpis',
            [$request->user()->office_id, $data['date'] ?? now()->toDateString()]
        );

        $kpis = json_decode($row[0]->kpis, true);

        return $this->ok($kpis);
    }
}
