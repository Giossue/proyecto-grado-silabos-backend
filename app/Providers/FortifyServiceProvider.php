<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureRateLimiting();
        ResetPasswordNotification::createUrlUsing(function (User $user, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            return $frontend.'/restablecer-contrasena?token='.urlencode($token)
                .'&email='.urlencode($user->getEmailForPasswordReset());
        });
    }

    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                ->where('correo_electronico', Str::lower((string) $request->input('correo_electronico')))
                ->where('activo', true)
                ->first();

            return $user && Hash::check((string) $request->input('password'), $user->contrasena)
                ? $user
                : null;
        });
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            $loginId = $request->session()->get('login.id');

            return Limit::perMinute(10)->by(is_string($loginId) ? 'two-factor:'.$loginId : 'two-factor-ip:'.$request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email', $request->input(Fortify::username()));
            $throttleKey = Str::transliterate(Str::lower((string) $email).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('password-recovery', function (Request $request) {
            $email = Str::lower($request->string('email')->toString());

            return Limit::perMinute(3)->by(Str::transliterate($email.'|'.$request->ip()));
        });

        // Solo para pedir el enlace: además del tope por minuto, 10 al día por cuenta, desde cualquier IP,
        // para que nadie llene de correos a una persona.
        RateLimiter::for('password-recovery-link', function (Request $request) {
            $email = Str::transliterate(Str::lower($request->string('email')->toString()));

            return [
                Limit::perMinute(3)->by($email.'|'.$request->ip()),
                Limit::perDay(10)->by('dia:'.$email),
            ];
        });
    }
}
