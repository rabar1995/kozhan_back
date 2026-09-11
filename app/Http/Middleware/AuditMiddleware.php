<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditMiddleware
{
    /**
     * After the response, persist the request to the audit_logs table.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            try {
                $segments = $request->segments();

                AuditLog::create([
                    'office_id' => $user->office_id,
                    'user_id' => $user->id,
                    'action' => $request->route()?->getName() ?? $request->method().' '.$request->path(),
                    'entity_type' => $segments[1] ?? 'api',
                    'entity_id' => $request->route('id'),
                    'old_values' => null,
                    'new_values' => $request->except(['password', 'token']),
                    'ip_address' => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }
}
