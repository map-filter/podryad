<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'contractor' => \App\Http\Middleware\EnsureContractor::class,
            'customer' => \App\Http\Middleware\EnsureCustomer::class,
            'token.query' => \App\Http\Middleware\QueryTokenToHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Войдите в кабинет'], 401);
        });
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $msg = $e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 'Запись не найдена' : ($e->getMessage() ?: 'Не найдено');

                return response()->json(['message' => $msg], 404);
            }
        });
    })->create();
