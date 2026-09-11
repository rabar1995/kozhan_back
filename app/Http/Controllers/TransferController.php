<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Http\Requests\StoreTransferRequest;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TransferController extends Controller
{
    use ApiResponse;

    public function __construct(private TransferService $transfers)
    {
    }

    /**
     * Internal transfer between two accounts (owner only).
     */
    public function store(StoreTransferRequest $request): JsonResponse
    {
        try {
            $transfer = $this->transfers->transfer($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($transfer->load(['fromAccount', 'toAccount', 'currency']), 'Transfer executed.', 201);
    }

    /**
     * Transfer history.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = Transfer::with(['fromAccount:id,name', 'toAccount:id,name', 'currency:id,code,symbol', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return $this->ok($rows);
    }
}
