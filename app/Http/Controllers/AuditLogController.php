<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use ApiResponse;

    /**
     * Audit trail (owner only), filterable by user and entity type.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = AuditLog::query()
            ->with(['user:id,name,role'])
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->string('user_id')))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->string('entity_type')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return $this->ok($rows);
    }
}
