<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Modules\Configuration\Domain\TableLayout;
use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $plantilla_id
 * @property string $seccion_plantilla_id
 * @property string $clave
 * @property string $tipo
 * @property string $titulo
 * @property array<string, mixed>|null $configuracion
 * @property int $posicion
 * @property-read SyllabusTemplate $template
 * @property-read TemplateSection $section
 */
class TemplateBlock extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'seccion_plantilla_id' => 'componente_padre_id',
        'clave' => 'clave_componente_plantilla',
        'tipo' => 'tipo_componente_plantilla',
        'titulo' => 'titulo_componente_plantilla',
        'configuracion' => 'configuracion_componente_plantilla',
        'posicion' => 'posicion_componente_plantilla',
    ];

    public $timestamps = false;

    protected $table = 'componentes_plantilla';

    /** @var list<string> */
    protected $fillable = ['plantilla_id', 'seccion_plantilla_id', 'clave', 'tipo', 'titulo', 'configuracion', 'posicion'];

    protected static function booted(): void
    {
        static::addGlobalScope('bloque', function (Builder $builder): void {
            $table = $builder->getModel()->getTable();
            $builder->where("{$table}.tipo_componente_plantilla", '<>', 'seccion')
                ->whereNotNull("{$table}.componente_padre_id");
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['configuracion' => 'array', 'posicion' => 'integer'];
    }

    /** @return BelongsTo<SyllabusTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SyllabusTemplate::class, 'plantilla_id');
    }

    /** @return BelongsTo<TemplateSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(TemplateSection::class, 'componente_padre_id');
    }

    /** @return HasMany<FieldDefinition, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FieldDefinition::class, 'componente_plantilla_id')->orderBy('posicion');
    }

    public function configuredContentType(): ?string
    {
        $configuration = $this->getAttribute('configuracion');

        if (! is_array($configuration)) {
            return null;
        }

        $contentType = $configuration['content_type'] ?? null;

        return is_string($contentType) ? $contentType : null;
    }

    public function pageOrientation(): ?string
    {
        $orientation = is_array($this->configuracion)
            ? ($this->configuracion['page_orientation'] ?? null)
            : null;
        if (in_array($orientation, ['portrait', 'landscape'], true)) {
            return $orientation;
        }

        $layout = TableLayout::fromBlock($this);

        return $layout !== null && TableLayout::isPlanning($layout) ? 'landscape' : null;
    }
}
