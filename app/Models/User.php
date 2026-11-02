<?php

namespace App\Models;

use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Support\Database\MapsLegacyColumnNames;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $id
 * @property string $nombre
 * @property string $correo_electronico
 * @property Carbon|null $correo_verificado_en
 * @property string $contrasena
 * @property bool $activo
 * @property string|null $formacion_experiencia
 * @property array{high_contrast: bool, text_size: string, no_motion: bool, wide_spacing: bool}|null $preferencias_accesibilidad
 * @property string|null $secreto_dos_factores
 * @property string|null $codigos_recuperacion_dos_factores
 * @property Carbon|null $dos_factores_confirmado_en
 * @property string|null $codigo_recordarme
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property mixed $two_factor_confirmed_at
 */
#[Fillable(['nombre', 'correo_electronico', 'correo_verificado_en', 'contrasena', 'activo'])]
#[Hidden(['contrasena', 'secreto_dos_factores', 'codigos_recuperacion_dos_factores', 'codigo_recordarme'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, MapsLegacyColumnNames, MustVerifyEmail, Notifiable, TwoFactorAuthenticatable;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'usuario_id',
        'nombre' => 'nombre_usuario',
        'activo' => 'usuario_activo',
        'formacion_experiencia' => 'formacion_experiencia_usuario',
        'preferencias_accesibilidad' => 'preferencias_accesibilidad_usuario',
    ];

    public $timestamps = false;

    protected $table = 'usuarios';

    protected $primaryKey = 'usuario_id';

    protected $authPasswordName = 'contrasena';

    protected $rememberTokenName = 'codigo_recordarme';

    /** @return HasMany<RoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class, 'usuario_id');
    }

    public function getEmailForPasswordReset(): string
    {
        return $this->correo_electronico;
    }

    public function getEmailForVerification(): string
    {
        return $this->correo_electronico;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->correo_verificado_en !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill(['correo_verificado_en' => $this->freshTimestamp()])->save();
    }

    public function markEmailAsUnverified(): bool
    {
        return $this->forceFill(['correo_verificado_en' => null])->save();
    }

    public function routeNotificationForMail(): string
    {
        return $this->correo_electronico;
    }

    /** @return Attribute<string|null, string|null> */
    protected function twoFactorSecret(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): ?string => $attributes['secreto_dos_factores'] ?? null,
            set: fn (mixed $value): array => ['secreto_dos_factores' => $value],
        );
    }

    /** @return Attribute<string|null, string|null> */
    protected function twoFactorRecoveryCodes(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): ?string => $attributes['codigos_recuperacion_dos_factores'] ?? null,
            set: fn (mixed $value): array => ['codigos_recuperacion_dos_factores' => $value],
        );
    }

    /** @return Attribute<mixed, mixed> */
    protected function twoFactorConfirmedAt(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): mixed => $attributes['dos_factores_confirmado_en'] ?? null,
            set: fn (mixed $value): array => [
                'dos_factores_confirmado_en' => $value === null ? null : $this->fromDateTime($value),
            ],
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'correo_verificado_en' => 'datetime',
            'contrasena' => 'hashed',
            'activo' => 'boolean',
            'preferencias_accesibilidad' => 'array',
            'dos_factores_confirmado_en' => 'datetime',
        ];
    }
}
