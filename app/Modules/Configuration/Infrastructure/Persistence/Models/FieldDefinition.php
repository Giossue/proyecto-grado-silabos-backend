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
 * @property list<mixed>|null $opciones
 * @property string|null $marcador_documento
 * @property array<string, mixed>|null $mostrar_si
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
        'opciones',
        'marcador_documento',
        'mostrar_si',
        'posicion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'heredado' => 'boolean',
            'editable_docente' => 'boolean',
            'ia_habilitada' => 'boolean',
            'opciones' => 'array',
            'mostrar_si' => 'array',
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

    /** Nombre para mensajes: una parte sin nombre toma el de su sección. */
    public function displayName(): string
    {
        $label = trim((string) $this->etiqueta);
        if ($label !== '') {
            return $label;
        }
        $block = $this->block;
        $blockTitle = trim((string) $block?->titulo);

        return $blockTitle !== '' ? $blockTitle : trim((string) $block?->section->titulo);
    }
}
