<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Models\User;
use App\Modules\Identity\Application\Actions\SelectActiveRole;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Identity\Presentation\Http\Requests\Api\LoginRequest;
use App\Modules\Identity\Presentation\Http\Requests\SelectActiveRoleRequest;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return response()->json($this->sessionPayload($user, $request));
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = Str::lower($request->string('email')->toString());
        $throttleKey = Str::transliterate($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Demasiados intentos. Intente nuevamente en unos minutos.',
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($throttleKey));
        }

        $user = User::query()
            ->where('correo_electronico', $email)
            ->where('activo', true)
            ->first();

        if (! $user instanceof User || ! Hash::check($request->string('password')->toString(), $user->contrasena)) {
            RateLimiter::hit($throttleKey, 60);

            return response()->json([
                'message' => 'Las credenciales proporcionadas no son válidas.',
            ], 422);
        }

        RateLimiter::clear($throttleKey);
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put('login.id', $user->id);

            return response()->json([
                'two_factor_required' => true,
                'message' => 'Ingrese el código de su aplicación de autenticación.',
            ], 202);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $this->audit->execute(
            actorId: $user->id,
            roleAssignmentId: null,
            action: 'sesion.iniciada',
            resourceType: 'usuario',
            resourceId: $user->id,
            result: 'exito',
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );

        return response()->json($this->sessionPayload($user, $request));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->audit->execute(
                actorId: $user->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'sesion.cerrada',
                resourceType: 'usuario',
                resourceId: $user->id,
                result: 'exito',
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }

    public function updatePassword(PasswordUpdateRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $wasTemporary = $user->debe_cambiar_contrasena;

        $user->update([
            'contrasena' => $request->string('password')->toString(),
            'debe_cambiar_contrasena' => false,
        ]);

        if ($wasTemporary) {
            $this->audit->execute(
                actorId: $user->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'usuario.contrasena_temporal_cambiada',
                resourceType: 'usuario',
                resourceId: $user->id,
                result: 'exito',
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
        }

        return response()->json($this->sessionPayload($user->fresh(), $request));
    }

    public function selectActiveRole(SelectActiveRoleRequest $request, SelectActiveRole $action): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $action->execute($user, $request->string('role_assignment_id')->toString(), $request);

        return response()->json($this->sessionPayload($user, $request));
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return array{user: array{id: string, name: string, email: string, email_verified: bool, must_change_password: bool}, active_role: array{id: string, code: string, career_id: string|null, career_name: string|null}|null, available_roles: list<array{id: string, code: string, career_id: string|null, career_name: string|null}>} */
    private function sessionPayload(User $user, Request $request): array
    {
        $activeRole = $this->roles->resolve($request);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->nombre,
                'email' => $user->correo_electronico,
                'email_verified' => $user->hasVerifiedEmail(),
                'must_change_password' => $user->debe_cambiar_contrasena,
            ],
            'active_role' => $activeRole === null ? null : $this->rolePayload($activeRole),
            'available_roles' => array_values($this->roles->eligible($user)
                ->map(fn (RoleAssignment $assignment): array => $this->rolePayload($assignment))
                ->all()),
        ];
    }

    /** @return array{id: string, code: string, career_id: string|null, career_name: string|null} */
    private function rolePayload(RoleAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'code' => $assignment->role->codigo_rol,
            'career_id' => $assignment->carrera_id,
            'career_name' => $assignment->career?->nombre,
        ];
    }
}
