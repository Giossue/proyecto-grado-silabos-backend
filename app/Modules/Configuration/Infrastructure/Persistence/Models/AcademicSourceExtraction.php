<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $fuente_academica_id
 * @property string $objeto_pdf_id
 * @property string|null $ejecucion_trabajo_id
 * @property string $solicitada_por
 * @property string $estado
 * @property string $modelo_ocr
 * @property string $version_adaptador
 * @property string $huella_pdf
 * @property array<string, mixed>|null $opciones
 * @property list<array<string, mixed>>|null $paginas
 * @property array<string, mixed>|null $metadatos
 * @property string|null $huella_contenido
 * @property string|null $codigo_error
 * @property string|null $mensaje_error
 * @property CarbonImmutable $solicitada_en
 * @property CarbonImmutable|null $iniciada_en
 * @property CarbonImmutable|null $completada_en
 * @property CarbonImmutable|null $fallida_en
 */
class AcademicSourceExtraction extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    public const CREATED_AT = 'solicitada_en';

    public const UPDATED_AT = null;

    protected $table = 'extracciones_fuente_academica';

    /** @var list<string> */
    protected $fillable = [
        'fuente_academica_id',
        'objeto_pdf_id',
        'ejecucion_trabajo_id',
        'solicitada_por',
        'estado',
        'modelo_ocr',
        'version_adaptador',
        'huella_pdf',
        'opciones',
        'paginas',
        'metadatos',
        'huella_contenido',
        'codigo_error',
        'mensaje_error',
        'solicitada_en',
        'iniciada_en',
        'completada_en',
        'fallida_en',
    ];

    /** @var array<string, string> */
    protected const LEGACY_COLUMN_ALIASES = [
        'estado' => 'estado_extraccion_fuente',
        'opciones' => 'opciones_extraccion',
        'paginas' => 'paginas_extraidas',
        'metadatos' => 'metadatos_respuesta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opciones' => 'array',
            'paginas' => 'array',
            'metadatos' => 'array',
            'solicitada_en' => 'immutable_datetime',
            'iniciada_en' => 'immutable_datetime',
            'completada_en' => 'immutable_datetime',
            'fallida_en' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AcademicSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(AcademicSource::class, 'fuente_academica_id');
    }

    /** @return BelongsTo<StoredObject, $this> */
    public function pdfObject(): BelongsTo
    {
        return $this->belongsTo(StoredObject::class, 'objeto_pdf_id');
    }

    /** @return BelongsTo<JobExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(JobExecution::class, 'ejecucion_trabajo_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitada_por');
    }
}
