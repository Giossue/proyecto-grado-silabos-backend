<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Concerns\ImmutableRecord;
use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fotografía de la fuente citada en el momento del análisis: nombre, extracto y huella
 * se conservan aunque la Coordinación edite el documento después.
 *
 * @property string $id
 * @property string $ejecucion_ia_id
 * @property string $tipo_evidencia_ia
 * @property string|null $definicion_campo_id
 * @property string|null $fuente_academica_id
 * @property string|null $fragmento_fuente_academica_id
 * @property string $etiqueta_evidencia_ia
 * @property string $extracto
 * @property string $huella_contenido
 */
class AiEvidence extends Model
{
    use HasUuids, ImmutableRecord, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'extracto' => 'extracto_evidencia_ia',
    ];

    public $timestamps = false;

    protected $table = 'evidencias_ia';

    /** @var list<string> */
    protected $fillable = [
        'ejecucion_ia_id', 'tipo_evidencia_ia', 'definicion_campo_id',
        'fuente_academica_id', 'fragmento_fuente_academica_id',
        'etiqueta_evidencia_ia', 'extracto', 'huella_contenido',
    ];

    /** @return BelongsTo<AiExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AiExecution::class, 'ejecucion_ia_id');
    }
}
