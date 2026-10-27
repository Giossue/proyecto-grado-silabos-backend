<?php

namespace App\Modules\Operations\Infrastructure\Persistence\Models;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Support\Database\MapsLegacyColumnNames;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $usuario_id
 * @property string $tipo
 * @property string $titulo
 * @property string $mensaje
 * @property string|null $tipo_recurso
 * @property string|null $recurso_id
 * @property string|null $periodo_academico_id
 * @property CarbonImmutable|null $leido_en
 * @property CarbonImmutable $notificado_en
 */
class InternalNotification extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'notificacion_id',
        'tipo' => 'tipo_notificacion',
        'titulo' => 'titulo_notificacion',
        'mensaje' => 'mensaje_notificacion',
        'clave_deduplicacion' => 'clave_unica',
    ];

    public const CREATED_AT = 'notificado_en';

    public const UPDATED_AT = null;

    protected $table = 'notificaciones';

    protected $primaryKey = 'notificacion_id';

    /** @var list<string> */
    protected $fillable = [
        'usuario_id', 'clave_deduplicacion', 'tipo', 'titulo', 'mensaje',
        'tipo_recurso', 'recurso_id', 'periodo_academico_id', 'leido_en', 'notificado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['leido_en' => 'immutable_datetime', 'notificado_en' => 'immutable_datetime'];
    }

    /** @param  Builder<InternalNotification>  $query */
    public function scopeInCurrentPeriod(Builder $query): void
    {
        $query->whereIn('periodo_academico_id', AcademicPeriod::query()
            ->whereDate('fecha_fin_periodo', '>=', InstitutionDate::todayOn())
            ->select('periodo_academico_id'));
    }

    protected static function booted(): void
    {
        static::updating(function (InternalNotification $notification): void {
            if (array_diff(array_keys($notification->getDirty()), ['leido_en']) !== []) {
                throw new LogicException('El contenido de una notificación es inmutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Una notificación no puede eliminarse.'));
    }
}
