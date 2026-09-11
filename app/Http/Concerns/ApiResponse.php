<?php

namespace App\Http\Concerns;

trait ApiResponse
{
    /**
     * Success envelope: { success: true, data: ..., message: ... }.
     */
    protected function ok(mixed $data = null, string $message = 'OK', int $status = 200)
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    /**
     * Failure envelope: { success: false, data: ..., message: ... }.
     */
    protected function fail(string $message = 'Error', int $status = 400, mixed $data = null)
    {
        return response()->json([
            'success' => false,
            'data' => $data,
            'message' => $message,
        ], $status);
    }
}
