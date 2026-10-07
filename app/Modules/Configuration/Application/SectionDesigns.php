<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\FieldTypes\FieldTypeRegistry;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SectionDesigns
{
    public function __construct(private readonly FieldTypeRegistry $types) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** @return list<string> */
    public function baseline(): array
    {
        /** @var list<string> $keys */
        $keys = config('syllabus_template.baseline', []);

        return $keys;
    }

    /** @return list<array{key: string, title: string, summary: string, fields: list<array{label: string, type: string}>, in_use: bool}> */
    public function options(SyllabusTemplate $template): array
    {
        $used = $this->usedKeys($template);

        return array_map(fn (array $design, string $key): array => [
            'key' => $key,
            'title' => $design['title'],
            'summary' => $design['summary'],
            'fields' => array_map(fn (array $field): array => ['label' => $field[1], 'type' => $field[2]], $design['fields']),
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
        foreach ($design['fields'] as $index => [$fieldKey, $label, $typeKey, $rawConfig]) {
            $type = $this->types->get($typeKey);
            $config = $type->normalizeConfig($this->expandConfig($rawConfig));
            $blockId = (string) Str::uuid();

            $blocks[] = [
                'id' => $blockId,
                'clave' => $fieldKey,
                'titulo' => $label,
                'configuracion' => [...($design['orientation'] ?? null) === 'landscape' ? ['page_orientation' => 'landscape'] : [], 'content_type' => 'text', 'document' => $typeKey === 'texto' || $typeKey === 'ficha'
                    ? TemplateDocumentDefaults::freeTextSheet($fieldKey, $label)
                    : TemplateDocumentDefaults::blankSheet()],
                'posicion' => $index + 1,
            ];
            $definitions[] = [
                'plantilla_id' => $templateId,
                'campo_plantilla_id' => $blockId,
                'clave' => $fieldKey,
                'etiqueta' => $label,
                'tipo' => $typeKey,
                'posicion' => 1,
                'opciones' => $config === [] ? null : $config,
                'mostrar_si' => null,
                ...$this->inheritance($typeKey, $config),
            ];
        }

        return [
            'section' => [
                'id' => (string) Str::uuid(),
                'clave' => $key,
                'titulo' => $title !== null && trim($title) !== '' ? trim($title) : $design['title'],
                'descripcion' => null,
                'posicion' => $position,
                'bloques' => $blocks,
            ],
            'definitions' => $definitions,
        ];
    }

    /** @return array<string, array{title: string, summary: string, fields: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>}> */
    private function all(): array
    {
        /** @var array<string, array{title: string, summary: string, fields: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>}> $designs */
        $designs = config('syllabus_template.designs', []);

        return $designs;
    }

    /** @return array{sections: list<string>, fields: list<string>} */
    private function usedKeys(SyllabusTemplate $template): array
    {
        return [
            'sections' => array_values(array_map(strval(...), $template->sections()->pluck('clave')->all())),
            'fields' => array_values(array_map(strval(...), FieldDefinition::query()->where('plantilla_id', $template->id)->pluck('clave')->all())),
        ];
    }

    /**
     * @param  array{title: string, summary: string, fields: list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>}  $design
     * @param  array{sections: list<string>, fields: list<string>}  $used
     */
    private function collides(array $design, string $key, array $used): bool
    {
        return in_array($key, $used['sections'], true)
            || array_intersect(array_column($design['fields'], 0), $used['fields']) !== [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function expandConfig(array $config): array
    {
        $axis = fn (array $items): array => array_map(
            fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
            array_keys($items),
            array_values($items),
        );
        if (isset($config['content']) && is_string($config['content'])) {
            $config['content'] = FixedContent::official($config['content']);
        }
        if (isset($config['weeks'])) {
            $config['rows'] = [];
            for ($week = 1; $week <= (int) $config['weeks']; $week++) {
                $config['rows']['semana_'.$week] = (string) $week;
            }
            unset($config['weeks']);
        }
        foreach (['columns', 'rows'] as $name) {
            if (isset($config[$name])) {
                $config[$name] = $axis($config[$name]);
            }
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function inheritance(string $typeKey, array $config): array
    {
        return match ($typeKey) {
            'ficha' => [
                'heredado' => true,
                'origen_maestro' => FieldDefinition::ORIGIN_PROFILE,
                'editable_docente' => (bool) ($config['adjustable'] ?? false),
            ],
            'automatico' => ['heredado' => true, 'origen_maestro' => (string) $config['source'], 'editable_docente' => false],
            'fijo' => ['heredado' => false, 'origen_maestro' => null, 'editable_docente' => false],
            default => ['heredado' => false, 'origen_maestro' => null, 'editable_docente' => true],
        };
    }
}
