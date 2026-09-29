<?php

use App\Http\Controllers\Health\ReadinessController;
use App\Modules\Configuration\Presentation\Http\Controllers\LogoController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', fn () => response()->json([
    'service' => 'Sílabos UEB API',
    'status' => 'ok',
]))->name('home');

Route::get('/health/ready', ReadinessController::class)
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        PreventRequestForgery::class,
    ])
    ->name('health.ready');

Route::get('logos/institucion', [LogoController::class, 'institution'])->name('logos.institution');
Route::get('logos/facultad/{faculty}', [LogoController::class, 'faculty'])
    ->whereUuid('faculty')
    ->name('logos.faculty');
