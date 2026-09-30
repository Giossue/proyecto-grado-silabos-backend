<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $carrera_id
 * @property string $nombre
 * @property array<string, mixed> $documento
 * @property bool $activo
 * @property string|null $objeto_pdf_id
 * @property string $estado_ingesta
 * @property CarbonImmutable|null $ingesta_actualizada_en
 */
class AcademicSource extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'nombre' => 'nombre_fuente_academica',
        'documento' => 'documento_fuente_academica',
        'activo' => 'fuente_academica_activa',
        'estado_ingesta' => 'estado_ingesta_fuente',
        'ingesta_actualizada_en' => 'ingesta_actualizada_en',
    ];

    public $timestamps = false;

    protected $table = 'fuentes_academicas';

    /** @var list<string> */
    protected $fillable = [
        'carrera_id',
        'nombre',
        'documento',
        'activo',
        'objeto_pdf_id',
        'estado_ingesta',
        'ingesta_actualizada_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'documento' => 'array',
            'ingesta_actualizada_en' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class, 'carrera_id');
    }

    /** @return BelongsTo<StoredObject, $this> */
    public function pdfObject(): BelongsTo
    {
        return $this->belongsTo(StoredObject::class, 'objeto_pdf_id');
    }

    /** @return HasOne<JobExecution, $this> */
    public function latestExecution(): HasOne
    {
        return $this->hasOne(JobExecution::class, 'recurso_id')
            ->where('tipo_recurso', 'fuente_academica')
            ->whereIn('tipo', ['fuente_academica.openparser', 'fuente_academica.paddleocr'])
            ->orderByDesc('encolado_en')
            ->orderByDesc('id');
    }
}
