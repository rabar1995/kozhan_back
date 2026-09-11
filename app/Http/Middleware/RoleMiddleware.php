<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Restrict the route to the given roles, e.g. ->middleware('role:owner').
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: insufficient role.',
            ], 403);
        }

        return $next($request);
    }
}
