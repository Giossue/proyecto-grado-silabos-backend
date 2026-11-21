<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequireActiveRole;
use App\Http\Middleware\RequireWebAppOrigin;
use App\Modules\Syllabus\Domain\Exceptions\DraftConflictException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
            'web-app' => RequireWebAppOrigin::class,
        ]);
        // The login and two-factor limiters read the session: the origin check goes first.
        $middleware->prependToPriorityList(ThrottleRequests::class, RequireWebAppOrigin::class);

        $middleware->statefulApi();

        // There are no server-side login pages: a guest gets a JSON 401, never a redirect to a
        // «login» route that does not exist (that answered 500; found by the OWASP ZAP scan).
        $middleware->redirectGuestsTo(fn (): ?string => null);

        $middleware->trimStrings(except: [
            'content', 'content.*', 'value', 'value.*', 'values.*', 'values.*.*',
            'sections.*.fields.*.content', 'sections.*.fields.*.content.*',
        ]);

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
        $exceptions->render(
            fn (PostTooLargeException $exception) => response()->json(['message' => 'El archivo es demasiado grande.'], 413),
        );
        // Laravel crea el «no autorizado» por defecto en inglés y sin traducir: aquí pasa por lang/es.json.
        $exceptions->render(
            fn (AccessDeniedHttpException $exception, Request $request) => $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => __($exception->getMessage() ?: 'This action is unauthorized.')], 403)
                : null,
        );
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
