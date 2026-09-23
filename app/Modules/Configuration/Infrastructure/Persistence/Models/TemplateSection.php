<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use Illuminate\Contracts\Routing\UrlRoutable;

/** Sección embebida dentro de `plantilla_silabo.documento_plantilla`. */
final class TemplateSection implements UrlRoutable
{
    public string $id;

    public string $plantilla_id;

    public string $clave;

    public string $titulo;

    public ?string $descripcion;

    public int $posicion;

    /** @param array<string, mixed> $data */
    public function __construct(public readonly SyllabusTemplate $template, private array $data)
    {
        $this->hydrate($data);
    }

    /** @return EmbeddedTemplateCollection<int, TemplateBlock> */
    public function blocks(): EmbeddedTemplateCollection
    {
        return new EmbeddedTemplateCollection(array_map(
            fn (array $block): TemplateBlock => new TemplateBlock($this->template, $this, $block),
            array_values($this->data['bloques'] ?? []),
        ));
    }

    public function __get(string $name): mixed
    {
        return $name === 'blocks' ? $this->blocks() : null;
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): bool
    {
        $fresh = $this->template->fresh()->updateSectionDocument($this->id, $attributes);
        $this->hydrate($fresh->data);

        return true;
    }

    public function delete(): bool
    {
        $this->template->fresh()->removeSectionDocument($this->id);

        return true;
    }

    public function fresh(): self
    {
        return $this->template->fresh()->sectionOrFail($this->id);
    }

    /** @return array<string, mixed> */
    public function toDocument(): array
    {
        return $this->data;
    }

    public function getRouteKey(): string
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): mixed
    {
        return null;
    }

    public function resolveChildRouteBinding($childType, $value, $field): mixed
    {
        return null;
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null): mixed
    {
        return null;
    }

    public function __toString(): string
    {
        return $this->id;
    }

    /** @param array<string, mixed> $data */
    private function hydrate(array $data): void
    {
        $this->data = $data;
        $this->id = (string) $data['id'];
        $this->plantilla_id = $this->template->id;
        $this->clave = (string) $data['clave'];
        $this->titulo = (string) $data['titulo'];
        $this->descripcion = is_string($data['descripcion'] ?? null) ? $data['descripcion'] : null;
        $this->posicion = (int) ($data['posicion'] ?? 1);
    }
}
