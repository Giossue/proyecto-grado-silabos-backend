<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\Api\ConfirmCurrentPasswordRequest;
use App\Modules\Identity\Presentation\Http\Requests\Api\ConfirmTwoFactorRequest;
use App\Modules\Identity\Presentation\Http\Requests\Api\TwoFactorChallengeRequest;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class TwoFactorController extends Controller
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function status(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return response()->json($this->statusPayload($user));
    }

    public function enable(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $enable($user);
        $fresh = $user->fresh();
        abort_unless($fresh instanceof User, 404);

        return response()->json([
            ...$this->statusPayload($fresh),
            'qr_code_svg' => $fresh->twoFactorQrCodeSvg(),
        ]);
    }

    public function confirm(ConfirmTwoFactorRequest $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $confirm($user, $request->string('code')->toString());
        $user->refresh();

        return response()->json([
            ...$this->statusPayload($user),
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    public function disable(Request $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $disable($user);

        return response()->json($this->statusPayload($user->fresh()));
    }

    public function regenerateRecoveryCodes(
        ConfirmCurrentPasswordRequest $request,
        GenerateNewRecoveryCodes $generate,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        abort_unless($user->hasEnabledTwoFactorAuthentication(), 422, 'Active primero la verificación en dos pasos.');
        $generate($user);
        $user->refresh();

        return response()->json([
            ...$this->statusPayload($user),
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    public function challenge(TwoFactorChallengeRequest $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $userId = $request->session()->get('login.id');
        $user = is_string($userId) ? User::query()->where('activo', true)->find($userId) : null;
        if (! $user instanceof User || ! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json(['message' => 'La sesión de verificación ya no es válida. Ingrese nuevamente.'], 422);
        }

        $throttleKey = 'two-factor-login:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json(['message' => 'Demasiados intentos. Intente nuevamente en unos minutos.'], 429);
        }

        $code = $request->string('code')->toString();
        $recoveryCode = $request->string('recovery_code')->toString();
        $valid = $code !== '' && $provider->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code);
        if (! $valid && $recoveryCode !== '') {
            $valid = collect($user->recoveryCodes())->contains(
                fn (string $stored): bool => hash_equals($stored, $recoveryCode),
            );
            if ($valid) {
                $user->replaceRecoveryCode($recoveryCode);
            }
        }
        if (! $valid) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['code' => 'El código de autenticación no es válido.']);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->forget('login.id');
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

        return response()->json(['message' => 'Segundo factor verificado.']);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return array{enabled: bool, confirmation_required: bool} */
    private function statusPayload(User $user): array
    {
        return [
            'enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'confirmation_required' => Fortify::confirmsTwoFactorAuthentication()
                && $user->two_factor_secret !== null
                && $user->two_factor_confirmed_at === null,
        ];
    }
}
