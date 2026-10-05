<?php

namespace App\Modules\Operations\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tipo
 * @property string $cola
 * @property string $estado
 * @property string $clave_idempotencia
 * @property string|null $tipo_recurso
 * @property string|null $recurso_id
 * @property array<string, mixed>|null $entrada
 * @property int $intentos
 * @property array<string, mixed>|null $resultado
 * @property string|null $mensaje_error
 * @property CarbonImmutable|null $iniciado_en
 * @property CarbonImmutable|null $finalizado_en
 * @property CarbonImmutable|null $encolado_en
 */
class JobExecution extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'trabajo_id',
        'tipo' => 'tipo_trabajo',
        'estado' => 'estado_trabajo',
        'resultado' => 'resultado_trabajo',
        'entrada' => 'datos_entrada',
        'clave_idempotencia' => 'clave_solicitud',
    ];

    public const CREATED_AT = 'encolado_en';

    public const UPDATED_AT = null;

    protected $table = 'trabajos_en_cola';

    protected $primaryKey = 'trabajo_id';

    /** @var list<string> */
    protected $fillable = [
        'tipo',
        'cola',
        'estado',
        'clave_idempotencia',
        'tipo_recurso',
        'recurso_id',
        'entrada',
        'intentos',
        'resultado',
        'mensaje_error',
        'iniciado_en',
        'finalizado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intentos' => 'integer',
            'resultado' => 'array',
            'entrada' => 'array',
            'iniciado_en' => 'immutable_datetime',
            'finalizado_en' => 'immutable_datetime',
            'encolado_en' => 'immutable_datetime',
        ];
    }
}
