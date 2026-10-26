<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias(['admin' => EnsureAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Réponses JSON homogènes pour l'API, avec l'identifiant de corrélation
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null; // rendu Laravel par défaut
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            $message = match (true) {
                $status === 429 => 'Trop de tentatives. Réessayez dans '.($headers['Retry-After'] ?? 60).' secondes.',
                $status >= 500 && ! config('app.debug') => 'Erreur interne. Communiquez l\'identifiant de requête au support.',
                default => $e->getMessage() ?: 'Erreur',
            };

            return response()->json([
                'error' => $status >= 500 ? 'server_error' : 'http_'.$status,
                'message' => $message,
                'request_id' => app()->bound('request_id') ? app('request_id') : null,
            ], $status, $headers);
        });

        $exceptions->context(fn () => ['source' => 'backend']);
    })->create();
