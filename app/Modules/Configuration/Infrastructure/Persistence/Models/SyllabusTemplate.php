<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La plantilla institucional. Una sola, editable en el sitio (I-32): cada revisión
 * enviada conserva su propia copia de la estructura, así que aquí no hay versiones.
 *
 * @property string $id
 * @property array<string, mixed> $documento
 * @property array<string, mixed>|null $mapeo_documento
 * @property int $revision_configuracion
 * @property-read EmbeddedTemplateCollection<int, TemplateSection> $sections
 * @property-read EmbeddedTemplateCollection<int, TemplateBlock> $blocks
 */
class SyllabusTemplate extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'documento' => 'documento_plantilla',
        'mapeo_documento' => 'mapeo_documento_plantilla',
    ];

    public const INSTITUTIONAL_NAME = 'Plantilla institucional de sílabo';

    public $timestamps = false;

    protected $table = 'plantilla_silabo';

    /** @var list<string> */
    protected $fillable = ['documento', 'mapeo_documento'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'documento' => 'array',
            'mapeo_documento' => 'array',
            'revision_configuracion' => 'integer',
        ];
    }

    /**
     * La plantilla institucional es única (I-25/I-32): sílabos, procesos, exportaciones
     * y ejecuciones de IA no guardan referencia a ella (I-132).
     */
    public static function institutional(): self
    {
        return self::query()->firstOrFail();
    }

    /** @return EmbeddedTemplateCollection<int, TemplateSection> */
    public function sections(): EmbeddedTemplateCollection
    {
        return new EmbeddedTemplateCollection(array_map(
            fn (array $section): TemplateSection => new TemplateSection($this, $section),
            $this->document()['secciones'],
        ));
    }

    /** @return EmbeddedTemplateCollection<int, TemplateBlock> */
    public function blocks(): EmbeddedTemplateCollection
    {
        return new EmbeddedTemplateCollection(
            $this->sections()->flatMap(fn (TemplateSection $section) => $section->blocks()->all())->values()->all(),
        );
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

    /** @return EmbeddedTemplateCollection<int, TemplateSection> */
    public function getSectionsAttribute(): EmbeddedTemplateCollection
    {
        return $this->sections();
    }

    /** @return EmbeddedTemplateCollection<int, TemplateBlock> */
    public function getBlocksAttribute(): EmbeddedTemplateCollection
    {
        return $this->blocks();
    }

    public function section(string $id): ?TemplateSection
    {
        return $this->sections()->firstWhere('id', $id);
    }

    public function sectionOrFail(string $id): TemplateSection
    {
        return $this->section($id) ?? throw (new ModelNotFoundException)->setModel(self::class, [$id]);
    }

    public function block(string $id): ?TemplateBlock
    {
        return $this->blocks()->firstWhere('id', $id);
    }

    public function blockOrFail(string $id): TemplateBlock
    {
        return $this->block($id) ?? throw (new ModelNotFoundException)->setModel(self::class, [$id]);
    }

    /** @param array<string, mixed> $section */
    public function appendSection(array $section): TemplateSection
    {
        $document = $this->document();
        $document['secciones'][] = $section;
        $this->saveDocument($document);

        return $this->sectionOrFail((string) $section['id']);
    }

    /** @param array<string, mixed> $block */
    public function appendBlock(string $sectionId, array $block): TemplateBlock
    {
        $document = $this->document();
        foreach ($document['secciones'] as &$section) {
            if (($section['id'] ?? null) === $sectionId) {
                $section['bloques'][] = $block;
                $this->saveDocument($document);

                return $this->blockOrFail((string) $block['id']);
            }
        }

        throw (new ModelNotFoundException)->setModel(self::class, [$sectionId]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateSectionDocument(string $sectionId, array $attributes): TemplateSection
    {
        $document = $this->document();
        foreach ($document['secciones'] as &$section) {
            if (($section['id'] ?? null) === $sectionId) {
                $section = [...$section, ...$attributes];
                $this->saveDocument($document);

                return $this->sectionOrFail($sectionId);
            }
        }

        throw (new ModelNotFoundException)->setModel(self::class, [$sectionId]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateBlockDocument(string $blockId, array $attributes): TemplateBlock
    {
        $document = $this->document();
        foreach ($document['secciones'] as &$section) {
            foreach ($section['bloques'] as &$block) {
                if (($block['id'] ?? null) === $blockId) {
                    $block = [...$block, ...$attributes];
                    $this->saveDocument($document);

                    return $this->blockOrFail($blockId);
                }
            }
        }

        throw (new ModelNotFoundException)->setModel(self::class, [$blockId]);
    }

    public function removeSectionDocument(string $sectionId): void
    {
        $document = $this->document();
        $document['secciones'] = array_values(array_filter(
            $document['secciones'],
            fn (array $section): bool => ($section['id'] ?? null) !== $sectionId,
        ));
        $this->saveDocument($document);
    }

    public function removeBlockDocument(string $blockId): void
    {
        $document = $this->document();
        foreach ($document['secciones'] as &$section) {
            $section['bloques'] = array_values(array_filter(
                $section['bloques'],
                fn (array $block): bool => ($block['id'] ?? null) !== $blockId,
            ));
        }
        $this->saveDocument($document);
    }

    /** @param list<string> $sectionIds */
    public function reorderSections(array $sectionIds): void
    {
        $document = $this->document();
        $byId = collect($document['secciones'])->keyBy('id');
        $document['secciones'] = array_map(
            fn (string $id, int $position): array => [...$byId->get($id), 'posicion' => $position + 1],
            $sectionIds,
            array_keys($sectionIds),
        );
        $this->saveDocument($document);
    }

    /** @param list<string> $blockIds */
    public function reorderBlocks(string $sectionId, array $blockIds): void
    {
        $document = $this->document();
        foreach ($document['secciones'] as &$section) {
            if (($section['id'] ?? null) !== $sectionId) {
                continue;
            }
            $blocks = is_array($section['bloques'] ?? null) ? $section['bloques'] : [];
            $byId = collect($blocks)->keyBy('id');
            $section['bloques'] = array_map(
                fn (string $id, int $position): array => [...$byId->get($id), 'posicion' => $position + 1],
                $blockIds,
                array_keys($blockIds),
            );
            $this->saveDocument($document);

            return;
        }

        throw (new ModelNotFoundException)->setModel(self::class, [$sectionId]);
    }

    /** @return array{version_esquema: int, secciones: list<array<string, mixed>>} */
    public function document(): array
    {
        $document = $this->getAttribute('documento');

        return is_array($document)
            ? ['version_esquema' => 1, 'secciones' => array_values($document['secciones'] ?? [])]
            : ['version_esquema' => 1, 'secciones' => []];
    }

    /** @param array<string, mixed> $document */
    private function saveDocument(array $document): void
    {
        $this->setAttribute('documento', $document);
        $this->save();
        $this->refresh();
    }
}
