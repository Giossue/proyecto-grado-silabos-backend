<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login and the two-factor challenge live in the session of the web app. A call that does not come
 * from it has no session, so it is turned away before reading the credentials (it used to answer
 * 500; found by the OWASP ZAP scan).
 */
class RequireWebAppOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! EnsureFrontendRequestsAreStateful::fromFrontend($request)) {
            return response()->json(['message' => 'Inicie sesión desde la aplicación web.'], 400);
        }

        return $next($request);
    }
}
