<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

uses(TestCase::class);

it('protects every product endpoint with session and verified email', function (): void {
    $productRoutes = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'api.v1.'))
        ->reject(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'api.v1.authentication.'))
        ->reject(fn (Route $route): bool => $route->getName() === 'api.v1.session.show');

    expect($productRoutes)->not->toBeEmpty();

    $productRoutes->each(function (Route $route): void {
        $middleware = $route->gatherMiddleware();

        expect($middleware)
            ->toContain('auth:sanctum')
            ->toContain('verified');
    });
});
