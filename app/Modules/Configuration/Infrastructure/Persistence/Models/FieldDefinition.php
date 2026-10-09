<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string $quien_llena
 * @property array<string, mixed> $contenido
 * @property int $posicion
 * @property-read TemplateBlock|null $block
 */
class FieldDefinition extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    public const TEACHER = 'docente';

    public const COORDINATION = 'coordinacion';

    public const INSTITUTIONAL = 'institucional';

    public const RESPONSIBLES = [self::TEACHER, self::COORDINATION, self::INSTITUTIONAL];

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'definicion_campo_id',
        'clave' => 'clave_definicion_campo',
        'etiqueta' => 'etiqueta_definicion_campo',
        'ayuda' => 'ayuda_definicion_campo',
        'quien_llena' => 'quien_llena_definicion_campo',
        'contenido' => 'contenido_definicion_campo',
        'posicion' => 'posicion_definicion_campo',
    ];

    public $timestamps = false;

    protected $table = 'definiciones_campo';

    protected $primaryKey = 'definicion_campo_id';

    protected $attributes = [
        'quien_llena_definicion_campo' => 'docente',
        'contenido_definicion_campo' => '{"type":"doc","content":[{"type":"paragraph"}]}',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = ['plantilla_id', 'campo_plantilla_id', 'clave', 'etiqueta', 'ayuda', 'quien_llena', 'contenido', 'posicion'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['contenido' => 'array', 'posicion' => 'integer'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('vigente_en_plantilla', fn (Builder $query) => $query->where('vigente_en_plantilla', true));
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->withoutGlobalScope('vigente_en_plantilla');
    }

    public function fromProfile(): bool
    {
        return $this->quien_llena === self::COORDINATION;
    }

    public function teacherEditable(): bool
    {
        return $this->quien_llena === self::TEACHER;
    }

    /**
     * @return BelongsTo<SyllabusTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SyllabusTemplate::class, 'plantilla_id');
    }

    public function getBlockAttribute(): ?TemplateBlock
    {
        $template = $this->relationLoaded('template') ? $this->getRelation('template') : $this->template()->first();

        return $template instanceof SyllabusTemplate ? $template->block($this->campo_plantilla_id) : null;
    }

    public function displayName(): string
    {
        $label = trim($this->etiqueta);

        return $label !== '' ? $label : trim((string) $this->block?->section->titulo);
    }
}
