<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Application\ActiveRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveRole
{
    public function __construct(private readonly ActiveRole $roles) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->roles->resolve($request) === null) {
            abort(Response::HTTP_CONFLICT, 'Seleccione un rol vigente.');
        }

        return $next($request);
    }
}
