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
 * @property-read string $clave
 * @property-read string $etiqueta
 * @property string $quien_llena
 * @property array<string, mixed> $contenido
 * @property-read TemplateBlock|null $block
 * @property-read TemplateBlockRecord|null $blockRecord
 */
class FieldDefinition extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    public const CAREER = 'carrera';

    public const INSTITUTIONAL = 'institucional';

    public const RESPONSIBLES = [self::CAREER, self::INSTITUTIONAL];

    public const LEGACY_RESPONSIBLES = ['docente', 'coordinacion'];

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'definicion_campo_id',
        'quien_llena' => 'quien_llena_definicion_campo',
        'contenido' => 'contenido_definicion_campo',
    ];

    public $timestamps = false;

    protected $table = 'definiciones_campo';

    protected $primaryKey = 'definicion_campo_id';

    protected $attributes = [
        'quien_llena_definicion_campo' => self::CAREER,
        'contenido_definicion_campo' => '{"type":"doc","content":[{"type":"paragraph"}]}',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = ['plantilla_id', 'campo_plantilla_id', 'quien_llena', 'contenido'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['contenido' => 'array'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('vigente_en_plantilla', fn (Builder $query) => $query->where('vigente_en_plantilla', true));
        static::addGlobalScope('bloque', fn (Builder $query) => $query->with('blockRecord'));
    }

    public function newEloquentBuilder($query): Builder
    {
        return new FieldDefinitionBuilder($query);
    }

    /**
     * @return BelongsTo<TemplateBlockRecord, $this>
     */
    public function blockRecord(): BelongsTo
    {
        return $this->belongsTo(TemplateBlockRecord::class, 'campo_plantilla_id');
    }

    public function getClaveAttribute(): string
    {
        return (string) $this->blockRecord?->clave_campo_plantilla;
    }

    public function getEtiquetaAttribute(): string
    {
        return (string) $this->blockRecord?->titulo_campo_plantilla;
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->withoutGlobalScope('vigente_en_plantilla');
    }

    public static function responsible(mixed $value): mixed
    {
        return in_array($value, self::LEGACY_RESPONSIBLES, true) ? self::CAREER : $value;
    }

    public function career(): bool
    {
        return $this->quien_llena !== self::INSTITUTIONAL;
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
