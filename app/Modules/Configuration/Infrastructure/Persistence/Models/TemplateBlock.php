<?php

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** @property-read Collection<int, FieldDefinition> $fields */
final class TemplateBlock implements UrlRoutable
{
    public string $id;

    public string $plantilla_id;

    public string $seccion_plantilla_id;

    public string $clave;

    public string $tipo;

    public string $titulo;

    /** @var array<string, mixed> */
    public array $configuracion;

    public int $posicion;

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly SyllabusTemplate $template,
        public readonly TemplateSection $section,
        private array $data,
    ) {
        $this->hydrate($data);
    }

    /** @return Builder<FieldDefinition> */
    public function fields(): Builder
    {
        return FieldDefinition::query()
            ->where('plantilla_id', $this->plantilla_id)
            ->where('bloque_plantilla_id', $this->id)
            ->orderBy('posicion');
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'fields' => $this->fields()->get(),
            'section' => $this->section,
            'template' => $this->template,
            default => null,
        };
    }

    public function getAttribute(string $key): mixed
    {
        return $this->{$key} ?? null;
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): bool
    {
        $fresh = $this->template->fresh()->updateBlockDocument($this->id, $attributes);
        $this->hydrate($fresh->data);

        return true;
    }

    public function delete(): bool
    {
        $this->template->fresh()->removeBlockDocument($this->id);

        return true;
    }

    public function fresh(): self
    {
        return $this->template->fresh()->blockOrFail($this->id);
    }

    public function refresh(): self
    {
        $fresh = $this->fresh();
        $this->hydrate($fresh->data);

        return $this;
    }

    public function load(mixed $relations): self
    {
        return $this;
    }

    public function loadMissing(mixed $relations): self
    {
        return $this;
    }

    public function configuredContentType(): ?string
    {
        $contentType = $this->configuracion['content_type'] ?? null;

        return is_string($contentType) ? $contentType : null;
    }

    public function contentType(): string
    {
        return $this->configuredContentType() ?? 'text';
    }

    public function pageOrientation(): ?string
    {
        $orientation = $this->configuracion['page_orientation'] ?? null;

        return in_array($orientation, ['portrait', 'landscape'], true) ? $orientation : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'plantilla_id' => $this->plantilla_id,
            'seccion_plantilla_id' => $this->seccion_plantilla_id,
            'clave' => $this->clave,
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'configuracion' => $this->configuracion,
            'posicion' => $this->posicion,
        ];
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

    public function __toString(): string
    {
        return $this->id;
    }

    /** @return array<string, mixed> */
    public function toDocument(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    private function hydrate(array $data): void
    {
        $this->data = $data;
        $this->id = (string) $data['id'];
        $this->plantilla_id = $this->template->id;
        $this->seccion_plantilla_id = $this->section->id;
        $this->clave = (string) $data['clave'];
        $this->titulo = (string) $data['titulo'];
        $this->configuracion = is_array($data['configuracion'] ?? null) ? $data['configuracion'] : [];
        $this->tipo = 'narrativa';
        $this->posicion = (int) ($data['posicion'] ?? 1);
    }
}
