<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $mensaje_ia_id
 * @property string $tipo_evidencia_mensaje_ia
 * @property string|null $definicion_campo_id
 * @property string|null $fuente_academica_id
 * @property string $etiqueta_evidencia_mensaje_ia
 * @property string $extracto_evidencia_mensaje_ia
 */
class AiMessageEvidence extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'evidencias_mensaje_ia';

    /** @var list<string> */
    protected $fillable = [
        'mensaje_ia_id', 'tipo_evidencia_mensaje_ia', 'definicion_campo_id',
        'fuente_academica_id', 'etiqueta_evidencia_mensaje_ia',
        'extracto_evidencia_mensaje_ia', 'huella_evidencia_mensaje_ia', 'fijada_en',
    ];
}
