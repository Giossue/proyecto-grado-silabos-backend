<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $carrera_id
 * @property string $solicitado_por
 * @property string $estado_importacion
 * @property string|null $clave_idempotencia_importacion
 * @property list<array<string, mixed>> $documentos_importacion
 * @property array<string, mixed>|null $propuesta_importacion
 * @property list<array{id: string, subject_id: string|null, accept_conflict: bool}>|null $seleccion_aplicada
 * @property string|null $instrucciones_importacion
 * @property string|null $mensaje_error_importacion
 * @property CarbonImmutable $solicitado_en
 * @property CarbonImmutable|null $aplicado_en
 */
class CurricularImport extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['clave_idempotencia_importacion' => 'clave_solicitud'];

    public $timestamps = false;

    protected $table = 'importaciones_curriculares';

    /** @var list<string> */
    protected $fillable = ['carrera_id', 'solicitado_por', 'estado_importacion', 'clave_idempotencia_importacion', 'documentos_importacion', 'propuesta_importacion', 'seleccion_aplicada', 'instrucciones_importacion', 'mensaje_error_importacion', 'solicitado_en', 'aplicado_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'documentos_importacion' => 'array', 'propuesta_importacion' => 'array', 'seleccion_aplicada' => 'array',
            'solicitado_en' => 'immutable_datetime', 'aplicado_en' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class, 'carrera_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }
}
