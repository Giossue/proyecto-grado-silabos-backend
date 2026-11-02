<?php

namespace App\Modules\Operations\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\SystemSettings;
use App\Modules\Operations\Application\SystemSettingsConnection;
use App\Modules\Operations\Presentation\Http\Requests\UpdateSystemSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SystemSettingsController extends Controller
{
    public function show(SystemSettings $settings): JsonResponse
    {
        Gate::authorize('manage-system-settings');

        return response()->json($settings->describe());
    }

    public function update(string $section, UpdateSystemSettingsRequest $request, SystemSettings $settings, ActiveRole $roles): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $settings->save($section, $request->settings(), $actor, $roles->resolve($request)?->id);

        return response()->json($settings->describe());
    }

    public function test(string $section, Request $request, SystemSettingsConnection $connection): JsonResponse
    {
        Gate::authorize('manage-system-settings');

        return response()->json($section === 'ia' ? $connection->ai() : $connection->library());
    }
}
