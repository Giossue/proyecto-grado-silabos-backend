<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequireActiveRole;
use App\Modules\Syllabus\Domain\Exceptions\DraftConflictException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health/live',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active-role' => RequireActiveRole::class,
        ]);

        $middleware->statefulApi();

        $middleware->trimStrings(except: [
            'content', 'content.*', 'value', 'value.*', 'values.*', 'values.*.*',
            'sections.*.fields.*.content', 'sections.*.fields.*.content.*',
        ]);

        $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '*'))))));

        $middleware->trustHosts(at: ['^127\\.0\\.0\\.1$', '^localhost$']);

        $middleware->web(append: [
            AssignCorrelationId::class,
            EnsureActiveUser::class,
            AddSecurityHeaders::class,
        ]);

        $middleware->api(append: [
            AssignCorrelationId::class,
            EnsureActiveUser::class,
            AddSecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn (DraftConflictException $exception, Request $request) => $request->expectsJson()
                ? response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'conflicto_version_borrador',
                    'version_bloqueo_actual' => $exception->currentVersion,
                ], 409)
                : response()->json(['message' => $exception->getMessage()], 409),
        );
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
