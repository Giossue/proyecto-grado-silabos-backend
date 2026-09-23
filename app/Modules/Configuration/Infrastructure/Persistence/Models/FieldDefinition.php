<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $plantilla_id
 * @property string $bloque_plantilla_id
 * @property string $clave
 * @property string $etiqueta
 * @property string|null $ayuda
 * @property string $tipo
 * @property bool $heredado
 * @property string|null $origen_maestro
 * @property bool $editable_docente
 * @property bool $ia_habilitada
 * @property bool $ia_coordinacion_configurable
 * @property array<string, mixed>|null $reglas
 * @property list<mixed>|null $opciones
 * @property string|null $marcador_documento
 * @property int $posicion
 * @property-read TemplateBlock|null $block
 */
class FieldDefinition extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'clave' => 'clave_definicion_campo',
        'etiqueta' => 'etiqueta_definicion_campo',
        'ayuda' => 'ayuda_definicion_campo',
        'tipo' => 'tipo_definicion_campo',
        'reglas' => 'reglas_definicion_campo',
        'opciones' => 'opciones_definicion_campo',
        'posicion' => 'posicion_definicion_campo',
    ];

    public $timestamps = false;

    protected $table = 'definiciones_campo';

    /** @var list<string> */
    protected $fillable = [
        'plantilla_id',
        'bloque_plantilla_id',
        'clave',
        'etiqueta',
        'ayuda',
        'tipo',
        'heredado',
        'origen_maestro',
        'editable_docente',
        'ia_habilitada',
        'ia_coordinacion_configurable',
        'reglas',
        'opciones',
        'marcador_documento',
        'posicion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'heredado' => 'boolean',
            'editable_docente' => 'boolean',
            'ia_habilitada' => 'boolean',
            'ia_coordinacion_configurable' => 'boolean',
            'reglas' => 'array',
            'opciones' => 'array',
            'posicion' => 'integer',
        ];
    }

    /** @return BelongsTo<SyllabusTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SyllabusTemplate::class, 'plantilla_id');
    }

    public function getBlockAttribute(): ?TemplateBlock
    {
        $template = $this->relationLoaded('template')
            ? $this->getRelation('template')
            : $this->template()->first();

        return $template instanceof SyllabusTemplate
            ? $template->block($this->bloque_plantilla_id)
            : null;
    }
}
