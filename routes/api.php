<?php

use App\Http\Controllers\DashboardController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\SessionController;
use App\Modules\Syllabus\Presentation\Http\Controllers\SyllabusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('authentication/login', [SessionController::class, 'login'])
        ->middleware('throttle:login')
        ->name('api.v1.authentication.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('session', [SessionController::class, 'show'])->name('api.v1.session.show');
        Route::post('authentication/logout', [SessionController::class, 'logout'])->name('api.v1.authentication.logout');
        Route::put('authentication/password', [SessionController::class, 'updatePassword'])
            ->name('api.v1.authentication.password.update');
        Route::put('active-role', [SessionController::class, 'selectActiveRole'])
            ->name('api.v1.active-role.update');
        Route::get('dashboard', [DashboardController::class, 'index'])
            ->middleware('active-role')
            ->name('api.v1.dashboard.show');
        Route::get('teacher/syllabi', [SyllabusController::class, 'index'])
            ->middleware('active-role')
            ->name('api.v1.teacher.syllabi.index');
    });
});
