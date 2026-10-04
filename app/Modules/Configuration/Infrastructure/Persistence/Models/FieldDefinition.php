<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $plantilla_id
 * @property string $campo_plantilla_id
 * @property string $clave
 * @property string $etiqueta
 * @property string|null $ayuda
 * @property string $tipo
 * @property bool $heredado
 * @property string|null $origen_maestro
 * @property bool $editable_docente
 * @property list<mixed>|null $opciones
 * @property array<string, mixed>|null $mostrar_si
 * @property int $posicion
 * @property-read TemplateBlock|null $block
 */
class FieldDefinition extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'definicion_campo_id',
        'clave' => 'clave_definicion_campo',
        'etiqueta' => 'etiqueta_definicion_campo',
        'ayuda' => 'ayuda_definicion_campo',
        'tipo' => 'tipo_definicion_campo',
        'opciones' => 'opciones_definicion_campo',
        'posicion' => 'posicion_definicion_campo',
        'heredado' => 'heredado_definicion_campo',
        'origen_maestro' => 'origen_dato_definicion_campo',
        'editable_docente' => 'editable_docente_definicion_campo',
        'mostrar_si' => 'condicion_definicion_campo',
    ];

    public $timestamps = false;

    protected $table = 'definiciones_campo';

    protected $primaryKey = 'definicion_campo_id';

    /** @var list<string> */
    protected $fillable = [
        'plantilla_id',
        'campo_plantilla_id',
        'clave',
        'etiqueta',
        'ayuda',
        'tipo',
        'heredado',
        'origen_maestro',
        'editable_docente',
        'opciones',
        'mostrar_si',
        'posicion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'heredado' => 'boolean',
            'editable_docente' => 'boolean',
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
            ? $template->block($this->campo_plantilla_id)
            : null;
    }

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
