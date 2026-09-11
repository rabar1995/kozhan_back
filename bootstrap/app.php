<?php

use App\Http\Middleware\AuditMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'audit' => AuditMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'data' => ['errors' => $e->errors()],
                'message' => $e->getMessage(),
            ], 422);
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $e->getMessage() ?: 'HTTP error.',
            ], $e->getStatusCode(), $e->getHeaders());
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            if ($e instanceof Illuminate\Database\Eloquent\ModelNotFoundException
                || $e instanceof Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Not found.',
                ], 404);
            }

            if ($e instanceof Illuminate\Auth\Access\AuthorizationException) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Forbidden.',
                ], 403);
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => config('app.debug') ? $e->getMessage() : 'Server error.',
            ], $status);
        });
    })->create();
