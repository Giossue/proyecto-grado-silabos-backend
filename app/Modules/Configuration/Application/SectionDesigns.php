<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SectionDesigns
{
    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * @return list<string>
     */
    public function baseline(): array
    {
        /**
         * @var list<string> $keys
         */
        $keys = config('syllabus_template.baseline', []);

        return $keys;
    }

    /**
     * @return list<array{key: string, title: string, summary: string, orientation?: string, fields: list<array{label: string, quien_llena: string}>, in_use: bool}>
     */
    public function options(SyllabusTemplate $template): array
    {
        $used = $this->usedKeys($template);

        return array_map(fn (array $design, string $key): array => [
            'key' => $key,
            'title' => $design['title'],
            'summary' => $design['summary'],
            'fields' => array_map(fn (array $field): array => ['label' => $field[1], 'quien_llena' => $field[2]], $design['fields']),
            'in_use' => $this->collides($design, $key, $used),
        ], $this->all(), array_keys($this->all()));
    }

    public function assertAvailable(SyllabusTemplate $template, string $key): void
    {
        $design = $this->all()[$key] ?? throw ValidationException::withMessages(['design' => 'El diseño oficial elegido no existe.']);
        if ($this->collides($design, $key, $this->usedKeys($template))) {
            throw ValidationException::withMessages([
                'design' => "La plantilla ya tiene «{$design['title']}». Cada diseño oficial se agrega una sola vez; si necesita otra sección, créela en blanco.",
            ]);
        }
    }

    /**
     * @return array{section: array<string, mixed>, definitions: list<array<string, mixed>>}
     */
    public function instantiate(string $key, string $templateId, int $position, ?string $title = null): array
    {
        $design = $this->all()[$key] ?? throw ValidationException::withMessages(['design' => 'El diseño oficial elegido no existe.']);

        $blocks = [];
        $definitions = [];
        foreach ($design['fields'] as $index => [$fieldKey, $label, $responsible, $document]) {
            $blockId = (string) Str::uuid();

            $blocks[] = [
                'id' => $blockId,
                'clave' => $fieldKey,
                'titulo' => $label,
                'configuracion' => [],
                'posicion' => $index + 1,
            ];
            $definitions[] = [
                'plantilla_id' => $templateId,
                'campo_plantilla_id' => $blockId,
                'clave' => $fieldKey,
                'etiqueta' => $label,
                'quien_llena' => $responsible,
                'contenido' => FieldDocument::normalize($document),
                'posicion' => 1,
            ];
        }

        return [
            'section' => [
                'id' => (string) Str::uuid(),
                'clave' => $key,
                'titulo' => $title !== null && trim($title) !== '' ? trim($title) : $position.'. '.$design['title'],
                'descripcion' => null,
                'posicion' => $position,
                'bloques' => $blocks,
                'orientacion' => $design['orientation'] ?? 'portrait',
            ],
            'definitions' => $definitions,
        ];
    }

    /**
     * @return array<string, array{title: string, summary: string, orientation?: string, fields: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>}>
     */
    private function all(): array
    {
        /**
         * @var array<string, array{title: string, summary: string, orientation?: string, fields: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>}> $designs
         */
        $designs = config('syllabus_template.designs', []);

        return $designs;
    }

    /**
     * @return array{sections: list<string>, fields: list<string>}
     */
    private function usedKeys(SyllabusTemplate $template): array
    {
        return [
            'sections' => array_values(array_map(strval(...), $template->sections()->pluck('clave')->all())),
            'fields' => array_values(array_map(strval(...), FieldDefinition::query()->where('plantilla_id', $template->id)->pluck('clave')->all())),
        ];
    }

    /**
     * @param  array{title: string, summary: string, orientation?: string, fields: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>}  $design
     * @param  array{sections: list<string>, fields: list<string>}  $used
     */
    private function collides(array $design, string $key, array $used): bool
    {
        return in_array($key, $used['sections'], true)
            || array_intersect(array_column($design['fields'], 0), $used['fields']) !== [];
    }
}
