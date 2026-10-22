<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Modules\Identity\Application\Actions\RevokeUserSessions;
use App\Modules\Identity\Application\Actions\SelectActiveRole;
use App\Modules\Identity\Application\Actions\UpdateAccessibilityPreferences;
use App\Modules\Identity\Application\Actions\UpdateManagedUserProfile;
use App\Modules\Identity\Application\Actions\UpdateTeacherFormation;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Identity\Presentation\Http\Requests\Api\LoginRequest;
use App\Modules\Identity\Presentation\Http\Requests\SelectActiveRoleRequest;
use App\Modules\Identity\Presentation\Http\Requests\UpdateAccessibilityRequest;
use App\Modules\Identity\Presentation\Http\Requests\UpdateTeacherFormationRequest;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Support\InstitutionDate;
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
        private readonly RevokeUserSessions $sessions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return response()->json($this->sessionPayload($user, $request));
    }

    private static ?string $dummyHash = null;

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
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

        $hash = $user instanceof User ? $user->contrasena : self::dummyHash();
        $matches = Hash::check($request->string('password')->toString(), $hash);
        $accountKey = 'login-account:'.$email;

        if (RateLimiter::tooManyAttempts($accountKey, 20)) {
            return response()->json([
                'message' => 'Demasiados intentos. Intente nuevamente en unos minutos.',
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($accountKey));
        }

        if (! $user instanceof User || ! $matches) {
            RateLimiter::hit($throttleKey, 60);
            RateLimiter::hit($accountKey, 3600);

            return response()->json([
                'message' => 'Las credenciales proporcionadas no son válidas.',
            ], 422);
        }

        RateLimiter::clear($throttleKey);
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $request->boolean('remember'));
            $request->session()->put('login.at', now()->timestamp);

            return response()->json([
                'two_factor_required' => true,
                'message' => 'Ingrese el código de su aplicación de autenticación.',
            ], 202);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $this->audit->execute(
            actorId: $user->id,
            roleAssignmentId: null,
            action: 'sesion.iniciada',
            resourceType: 'usuario',
            resourceId: $user->id,
            result: 'exito',
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
        $user->update([
            'contrasena' => $request->string('password')->toString(),
        ]);
        $this->sessions->execute($user, $request->session()->getId());

        $this->audit->execute(
            actorId: $user->id,
            roleAssignmentId: $this->roles->resolve($request)?->id,
            action: 'usuario.contrasena_actualizada',
            resourceType: 'usuario',
            resourceId: $user->id,
            result: 'exito',
        );

        return response()->json($this->sessionPayload($user->fresh(), $request));
    }

    public function updateProfile(
        ProfileUpdateRequest $request,
        UpdateManagedUserProfile $action,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $updated = $action->execute($user, [
            'nombre' => $request->string('nombre')->toString(),
            'correo_electronico' => $request->string('correo_electronico')->toString(),
        ], $user, $request);

        return response()->json($this->sessionPayload($updated, $request));
    }

    public function updateFormation(
        UpdateTeacherFormationRequest $request,
        UpdateTeacherFormation $action,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $updated = $action->execute($user, $request->string('formacion_experiencia')->toString(), $request);

        return response()->json($this->sessionPayload($updated, $request));
    }

    public function updateAccessibility(
        UpdateAccessibilityRequest $request,
        UpdateAccessibilityPreferences $action,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $updated = $action->execute($user, [
            'high_contrast' => $request->boolean('high_contrast'),
            'text_size' => $request->string('text_size')->toString(),
            'no_motion' => $request->boolean('no_motion'),
            'underline_links' => $request->boolean('underline_links'),
            'wide_spacing' => $request->boolean('wide_spacing'),
        ]);

        return response()->json($this->sessionPayload($updated, $request));
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

    /** @return array{user: array{id: string, name: string, email: string, email_verified: bool, can_edit_identity: bool, formation: string|null, accessibility: array{high_contrast: bool, text_size: string, no_motion: bool, underline_links: bool, wide_spacing: bool}|null}, active_role: array{id: string, code: string, career_id: string|null, career_name: string|null}|null, available_roles: list<array{id: string, code: string, career_id: string|null, career_name: string|null}>, institution: array{today: string, timezone: string}} */
    private function sessionPayload(User $user, Request $request): array
    {
        $activeRole = $this->roles->resolve($request);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->nombre,
                'email' => $user->correo_electronico,
                'email_verified' => $user->hasVerifiedEmail(),
                'can_edit_identity' => $user->can('updateProfileData', $user),
                'formation' => $user->formacion_experiencia,
                'accessibility' => $user->preferencias_accesibilidad,
            ],
            'active_role' => $activeRole === null ? null : $this->rolePayload($activeRole),
            'available_roles' => array_values($this->roles->eligible($user)
                ->map(fn (RoleAssignment $assignment): array => $this->rolePayload($assignment))
                ->all()),
            'institution' => [
                'today' => InstitutionDate::todayOn(),
                'timezone' => InstitutionDate::timezone(),
            ],
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
