<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $plantilla_id
 * @property string $clave
 * @property string $titulo
 * @property string|null $descripcion
 * @property int $posicion
 * @property-read SyllabusTemplate $template
 */
class TemplateSection extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'clave' => 'clave_componente_plantilla',
        'titulo' => 'titulo_componente_plantilla',
        'descripcion' => 'descripcion_componente_plantilla',
        'posicion' => 'posicion_componente_plantilla',
    ];

    public $timestamps = false;

    protected $table = 'componentes_plantilla';

    /** @var list<string> */
    protected $fillable = ['plantilla_id', 'clave', 'titulo', 'descripcion', 'posicion'];

    protected static function booted(): void
    {
        static::addGlobalScope('seccion', function (Builder $builder): void {
            $table = $builder->getModel()->getTable();
            $builder->where("{$table}.tipo_componente_plantilla", 'seccion')
                ->whereNull("{$table}.componente_padre_id");
        });

        static::creating(function (self $section): void {
            $section->setAttribute('tipo_componente_plantilla', 'seccion');
        });
    }

    /** @return BelongsTo<SyllabusTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SyllabusTemplate::class, 'plantilla_id');
    }

    /** @return HasMany<TemplateBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(TemplateBlock::class, 'componente_padre_id')->orderBy('posicion');
    }
}
