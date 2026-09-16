<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $carrera_id
 * @property string $definicion_campo_id
 * @property bool $ia_habilitada
 * @property int $revision_plantilla_origen
 * @property string $configurado_por
 */
class CareerAiPreference extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'preferencias_ia_carrera';

    protected $fillable = [
        'carrera_id', 'definicion_campo_id', 'ia_habilitada',
        'revision_plantilla_origen', 'configurado_por', 'configurado_en',
    ];

    protected function casts(): array
    {
        return ['ia_habilitada' => 'boolean', 'revision_plantilla_origen' => 'integer', 'configurado_en' => 'immutable_datetime'];
    }
}
