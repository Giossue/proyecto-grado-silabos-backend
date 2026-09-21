<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La plantilla institucional. Una sola, editable en el sitio (I-32): cada revisión
 * enviada conserva su propia copia de la estructura, así que aquí no hay versiones.
 *
 * @property string $id
 * @property array<string, mixed>|null $mapeo_documento
 * @property int $sections_count
 * @property int $revision_configuracion
 */
class SyllabusTemplate extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'mapeo_documento' => 'mapeo_documento_plantilla',
    ];

    public const INSTITUTIONAL_NAME = 'Plantilla institucional de sílabo';

    public $timestamps = false;

    protected $table = 'plantillas_silabo';

    /** @var list<string> */
    protected $fillable = ['mapeo_documento'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mapeo_documento' => 'array', 'revision_configuracion' => 'integer'];
    }

    /** @return HasMany<TemplateSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(TemplateSection::class, 'plantilla_id')
            ->whereNull('componente_padre_id')
            ->orderBy('posicion');
    }

    /** @return HasMany<TemplateBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(TemplateBlock::class, 'plantilla_id');
    }

    /** @return HasMany<FieldDefinition, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FieldDefinition::class, 'plantilla_id');
    }

    public function hasStructure(): bool
    {
        return $this->sections()->exists()
            && $this->blocks()->exists()
            && $this->fields()->exists();
    }
}
