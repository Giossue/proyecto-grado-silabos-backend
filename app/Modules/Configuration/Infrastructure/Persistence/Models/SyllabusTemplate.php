<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property CarbonImmutable|null $plantilla_modificada_en
 * @property array{version_esquema: int, secciones: list<array<string, mixed>>} $documento
 * @property array<string, mixed>|null $definicion
 * @property-read EmbeddedTemplateCollection<int, TemplateSection> $sections
 * @property-read EmbeddedTemplateCollection<int, TemplateBlock> $blocks
 */
class SyllabusTemplate extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'plantilla_id',
        'definicion' => 'definicion_plantilla',
    ];

    /**
     * @var array{version_esquema: int, secciones: list<array<string, mixed>>}|null
     */
    private ?array $documentCache = null;

    /**
     * @var array<string, mixed>|null Documento asignado antes de guardar; se vuelca al guardar.
     */
    private ?array $pendingDocument = null;

    public const INSTITUTIONAL_NAME = 'Plantilla institucional de sílabo';

    public $timestamps = false;

    protected $table = 'plantilla_silabo';

    protected $primaryKey = 'plantilla_id';

    /**
     * @var list<string>
     */
    protected $fillable = ['documento', 'definicion'];

    protected $casts = [
        'plantilla_modificada_en' => 'immutable_datetime',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definicion' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $template): void {
            if ($template->pendingDocument !== null) {
                $document = $template->pendingDocument;
                $template->pendingDocument = null;
                $template->syncDocument($document);
            }
        });
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public function setDocumentoAttribute(?array $value): static
    {
        $this->pendingDocument = $value ?? ['version_esquema' => 1, 'secciones' => []];
        $this->documentCache = null;

        return $this;
    }

    /**
     * @return array{version_esquema: int, secciones: list<array<string, mixed>>}
     */
    public function getDocumentoAttribute(): array
    {
        return $this->document();
    }

    public function refresh(): static
    {
        $this->documentCache = null;

        return parent::refresh();
    }

    public static function institutional(): self
    {
        return self::query()->firstOrFail();
    }

    public function definition(): TemplateDefinition
    {
        $stored = $this->definicion;

        return is_array($stored) && $stored !== [] ? TemplateDefinition::trusted($stored) : OfficialTemplate::definition();
    }

    public function saveDefinition(TemplateDefinition $definition): void
    {
        $this->forceFill(['definicion' => $definition->toArray()])->save();
        app(TemplateContext::class)->forget();
    }

    /**
     * @return EmbeddedTemplateCollection<int, TemplateSection>
     */
    public function sections(): EmbeddedTemplateCollection
    {
        return new EmbeddedTemplateCollection(array_map(
            fn (array $section): TemplateSection => new TemplateSection($this, $section),
            $this->document()['secciones'],
        ));
    }

    /**
     * @return EmbeddedTemplateCollection<int, TemplateBlock>
     */
    public function blocks(): EmbeddedTemplateCollection
    {
        return new EmbeddedTemplateCollection(
            $this->sections()->flatMap(fn (TemplateSection $section) => $section->blocks()->all())->values()->all(),
        );
    }

    /**
     * @return HasMany<FieldDefinition, $this>
     */
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

    /**
     * @return EmbeddedTemplateCollection<int, TemplateSection>
     */
    public function getSectionsAttribute(): EmbeddedTemplateCollection
    {
        return $this->sections();
    }

    /**
     * @return EmbeddedTemplateCollection<int, TemplateBlock>
     */
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

    /**
     * @param  array<string, mixed>  $section
     */
    public function appendSection(array $section): TemplateSection
    {
        $document = $this->document();
        $document['secciones'][] = $section;
        $this->saveDocument($document);

        return $this->sectionOrFail((string) $section['id']);
    }

    /**
     * @param  array<string, mixed>  $block
     */
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

    /**
     * @param  array<string, mixed>  $attributes
     */
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

    /**
     * @param  array<string, mixed>  $attributes
     */
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

    /**
     * @param  list<string>  $sectionIds
     */
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

    /**
     * @param  list<string>  $blockIds
     */
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

    /**
     * @return array{version_esquema: int, secciones: list<array<string, mixed>>}
     */
    public function document(): array
    {
        if ($this->pendingDocument !== null) {
            return ['version_esquema' => 1, 'secciones' => array_values($this->pendingDocument['secciones'] ?? [])];
        }
        if ($this->documentCache === null) {
            $this->documentCache = ['version_esquema' => 1, 'secciones' => $this->exists ? $this->loadSections() : []];
        }

        return $this->documentCache;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadSections(): array
    {
        $blocks = DB::table('campos_plantilla')->where('plantilla_id', $this->id)->where('vigente_en_plantilla', true)
            ->orderBy('posicion_campo_plantilla')->orderBy('clave_campo_plantilla')->get()
            ->groupBy('seccion_plantilla_id');

        return array_values(DB::table('secciones_plantilla')->where('plantilla_id', $this->id)->where('vigente_en_plantilla', true)
            ->orderBy('posicion_seccion_plantilla')->orderBy('clave_seccion_plantilla')->get()
            ->map(fn (object $section): array => [
                'id' => $section->seccion_plantilla_id, 'clave' => $section->clave_seccion_plantilla, 'titulo' => $section->titulo_seccion_plantilla,
                'orientacion' => $section->orientacion_seccion_plantilla, 'posicion' => (int) $section->posicion_seccion_plantilla,
                'bloques' => ($blocks->get($section->seccion_plantilla_id) ?? collect())->map(fn (object $block): array => [
                    'id' => $block->campo_plantilla_id, 'clave' => $block->clave_campo_plantilla, 'titulo' => $block->titulo_campo_plantilla,
                    'posicion' => (int) $block->posicion_campo_plantilla,
                ])->values()->all(),
            ])->all());
    }

    private function purgeUnusedRetired(): void
    {
        DB::table('definiciones_campo')->where('plantilla_id', $this->id)->where('vigente_en_plantilla', false)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('valores_campo')
                ->whereColumn('valores_campo.definicion_campo_id', 'definiciones_campo.definicion_campo_id'))
            ->delete();
        DB::table('campos_plantilla')->where('plantilla_id', $this->id)->where('vigente_en_plantilla', false)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('definiciones_campo')
                ->whereColumn('definiciones_campo.campo_plantilla_id', 'campos_plantilla.campo_plantilla_id'))
            ->delete();
        DB::table('secciones_plantilla')->where('plantilla_id', $this->id)->where('vigente_en_plantilla', false)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('campos_plantilla')
                ->whereColumn('campos_plantilla.seccion_plantilla_id', 'secciones_plantilla.seccion_plantilla_id'))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function saveDocument(array $document): void
    {
        $this->syncDocument($document);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function syncDocument(array $document): void
    {
        $sections = array_values(is_array($document['secciones'] ?? null) ? $document['secciones'] : []);
        DB::transaction(function () use ($sections): void {
            $sectionIds = array_map(fn (array $section): string => (string) $section['id'], $sections);
            $blockIds = [];
            foreach ($sections as $section) {
                foreach (array_values(is_array($section['bloques'] ?? null) ? $section['bloques'] : []) as $block) {
                    $blockIds[] = (string) $block['id'];
                }
            }
            $retiredBlockIds = DB::table('campos_plantilla')->where('plantilla_id', $this->id)->whereNotIn('campo_plantilla_id', $blockIds)->pluck('campo_plantilla_id');
            DB::table('definiciones_campo')->whereIn('campo_plantilla_id', $retiredBlockIds)->update(['vigente_en_plantilla' => false]);
            DB::table('campos_plantilla')->whereIn('campo_plantilla_id', $retiredBlockIds)->update(['vigente_en_plantilla' => false]);
            DB::table('secciones_plantilla')->where('plantilla_id', $this->id)->whereNotIn('seccion_plantilla_id', $sectionIds)->update(['vigente_en_plantilla' => false]);
            $this->purgeUnusedRetired();

            foreach ($sections as $sectionIndex => $section) {
                DB::table('secciones_plantilla')->updateOrInsert(['seccion_plantilla_id' => (string) $section['id']], [
                    'plantilla_id' => $this->id,
                    'clave_seccion_plantilla' => (string) $section['clave'],
                    'titulo_seccion_plantilla' => (string) ($section['titulo'] ?? ''),
                    'orientacion_seccion_plantilla' => $section['orientacion'] ?? 'portrait',
                    'posicion_seccion_plantilla' => (int) ($section['posicion'] ?? $sectionIndex + 1),
                ]);
                foreach (array_values(is_array($section['bloques'] ?? null) ? $section['bloques'] : []) as $blockIndex => $block) {
                    DB::table('campos_plantilla')->updateOrInsert(['campo_plantilla_id' => (string) $block['id']], [
                        'seccion_plantilla_id' => (string) $section['id'],
                        'plantilla_id' => $this->id,
                        'clave_campo_plantilla' => (string) $block['clave'],
                        'titulo_campo_plantilla' => (string) ($block['titulo'] ?? ''),
                        'posicion_campo_plantilla' => (int) ($block['posicion'] ?? $blockIndex + 1),
                    ]);
                }
            }
        });
        $this->documentCache = null;
    }
}
