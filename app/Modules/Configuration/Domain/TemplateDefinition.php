<?php

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Domain\Blocks\BlockDefinition;
use App\Modules\Configuration\Domain\Blocks\BlockDefinitionError;
use App\Modules\Configuration\Domain\Blocks\RepeatableTable;
use App\Modules\Configuration\Domain\Blocks\ValueSchema;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;

final class TemplateDefinition
{
    public const SOURCES = [
        'identification.faculty', 'identification.career', 'identification.subject', 'identification.code',
        'identification.modality', 'identification.campus', 'identification.period', 'identification.cycle',
        'identification.parallel', 'identification.shift', 'identification.prerequisites', 'identification.corequisites',
        'identification.organization_unit', 'identification.hours_ac', 'identification.hours_pae', 'identification.hours_aa',
        'identification.total_hours', 'identification.credits', 'identification.teaching_weeks', 'identification.teacher', 'identification.email',
        'identification.formation', 'approval.submitted_on', 'approval.coordinator', 'approval.approved_on',
        'approval.librarian', 'approval.verified_on',
        'signature.docente', 'signature.coordinacion', 'signature.biblioteca',
    ];

    public const ROOT_KEYS = ['document', 'variables', 'blocks', 'required_sections', 'library_sections', 'presets', 'response_policy'];

    private const KEY = '/^[a-z][a-z0-9_]{0,99}$/';

    /**
     * @param  array<string, mixed>  $definition
     */
    private function __construct(private readonly array $definition) {}

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    public static function define(mixed $raw, array $sections = []): self
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            BlockDefinitionError::fail('La definición de la plantilla debe ser un objeto.');
        }
        $unknown = array_diff(array_keys($raw), self::ROOT_KEYS);
        if ($unknown !== []) {
            BlockDefinitionError::fail('Clave desconocida en la plantilla: «'.implode('», «', $unknown).'».');
        }
        $variables = self::defineVariables($raw['variables'] ?? []);
        if (($raw['response_policy'] ?? 'protected') !== 'protected') {
            BlockDefinitionError::fail('«response_policy» debe ser «protected».');
        }
        $keys = array_keys($variables);
        $blocks = [];
        $rawBlocks = $raw['blocks'] ?? [];
        if (! is_array($rawBlocks) || ($rawBlocks !== [] && array_is_list($rawBlocks))) {
            BlockDefinitionError::fail('«blocks» debe ser un objeto con una definición por bloque.');
        }
        foreach ($rawBlocks as $kind => $block) {
            $blocks[(string) $kind] = BlockDefinition::define((string) $kind, $block, $keys);
        }

        return new self([
            'response_policy' => 'protected',
            'document' => TemplateStyle::define($raw['document'] ?? []),
            'variables' => $variables,
            'blocks' => $blocks,
            'required_sections' => self::sectionKeys($raw['required_sections'] ?? [], 'required_sections'),
            'library_sections' => self::sectionKeys($raw['library_sections'] ?? [], 'library_sections'),
            'presets' => array_map(
                fn (array $preset): array => [...$preset, 'content' => TemplateDocument::normalize($preset['content'], $keys, null, $blocks)],
                self::definePresets($raw['presets'] ?? [], $sections),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function trusted(array $stored): self
    {
        foreach (is_array($stored['blocks'] ?? null) ? $stored['blocks'] : [] as $kind => $block) {
            foreach (['props', 'value'] as $part) {
                if (is_array($block[$part] ?? null)) {
                    $stored['blocks'][$kind][$part] = ValueSchema::upgradeAll($block[$part]);
                }
            }
        }

        return new self($stored);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition;
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return $this->definition['document'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function variables(): array
    {
        return $this->definition['variables'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function blocks(): array
    {
        return $this->definition['blocks'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function block(string $kind): ?array
    {
        return $this->definition['blocks'][$kind] ?? null;
    }

    /**
     * @return list<string>
     */
    public function requiredSections(): array
    {
        return $this->definition['required_sections'];
    }

    /**
     * @return list<string>
     */
    public function librarySections(): array
    {
        return $this->definition['library_sections'];
    }

    /**
     * @return list<array{key: string, label: string, field: string, quien_llena: string, content: array<string, mixed>}>
     */
    public function presets(): array
    {
        return $this->definition['presets'];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function withDocument(array $document): self
    {
        return new self([...$this->definition, 'document' => TemplateStyle::define($document)]);
    }

    /**
     * Cambia cuántos elementos admite cada lista de un bloque (por ejemplo, unidades y semanas).
     *
     * @param  list<array{block: string, path: string, min: int, max: int, initial: int}>  $lists
     */
    public function withListLimits(array $lists): self
    {
        $blocks = $this->definition['blocks'];
        $changed = [];
        foreach ($lists as $list) {
            $block = $blocks[$list['block']] ?? null;
            if ($block === null) {
                BlockDefinitionError::fail("«{$list['block']}» no es un bloque de la plantilla.");
            }
            $schema = self::withLimits(['type' => 'object', 'properties' => $block['value']], explode('.', $list['path']), $list);
            $blocks[$list['block']]['value'] = $schema['properties'];
            $changed[$list['block']] = true;
        }
        $variables = array_keys($this->variables());
        foreach (array_keys($changed) as $kind) {
            $blocks[$kind] = BlockDefinition::define($kind, $blocks[$kind], $variables);
        }

        return new self([...$this->definition, 'blocks' => $blocks]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function withBlocks(array $changes): self
    {
        $blocks = $this->definition['blocks'];
        $variables = array_keys($this->variables());
        foreach ($changes as $kind => $raw) {
            $changed = BlockDefinition::define((string) $kind, $raw, $variables);
            RepeatableTable::guard((string) $kind, $blocks[(string) $kind] ?? null, $changed, $variables);
            $blocks[(string) $kind] = $changed;
        }

        return new self([...$this->definition, 'blocks' => $blocks]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $path
     * @param  array{block: string, path: string, min: int, max: int, initial: int}  $limits
     * @return array<string, mixed>
     */
    private static function withLimits(array $schema, array $path, array $limits): array
    {
        $segment = array_shift($path);
        if ($segment === null) {
            if (($schema['type'] ?? null) !== 'array') {
                BlockDefinitionError::fail("«{$limits['path']}» no es una lista del bloque «{$limits['block']}».");
            }
            $name = $schema['title'] ?? $limits['path'];
            if ($limits['max'] < 1 || $limits['min'] > $limits['max']) {
                BlockDefinitionError::fail("«{$name}»: el máximo debe ser al menos 1 y no menor que el mínimo.");
            }
            if ($limits['initial'] < $limits['min'] || $limits['initial'] > $limits['max']) {
                BlockDefinitionError::fail("«{$name}»: «Al empezar» debe estar entre el mínimo y el máximo.");
            }

            return [...$schema, 'minItems' => $limits['min'], 'maxItems' => $limits['max'], 'initial' => $limits['initial']];
        }
        if ($segment === '*' && ($schema['type'] ?? null) === 'array') {
            return [...$schema, 'items' => self::withLimits($schema['items'], $path, $limits)];
        }
        if (($schema['type'] ?? null) === 'object' && isset($schema['properties'][$segment])) {
            $schema['properties'][$segment] = self::withLimits($schema['properties'][$segment], $path, $limits);

            return $schema;
        }
        BlockDefinitionError::fail("«{$limits['path']}» no es una lista del bloque «{$limits['block']}».");
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function defineVariables(mixed $raw): array
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            BlockDefinitionError::fail('«variables» debe ser un objeto con una variable por clave.');
        }
        $clean = [];
        foreach ($raw as $key => $variable) {
            $where = "variables.{$key}";
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                BlockDefinitionError::fail("{$where}: la clave usa minúsculas, números y _.");
            }
            if (! is_array($variable) || array_diff(array_keys($variable), ['label', 'source', 'sample', 'format', 'equals']) !== []) {
                BlockDefinitionError::fail("{$where}: use label, source y, si hace falta, sample, format y equals.");
            }
            if (! is_string($variable['label'] ?? null) || trim($variable['label']) === '' || mb_strlen($variable['label']) > 120) {
                BlockDefinitionError::fail("{$where}.label: escriba el nombre (hasta 120 caracteres).");
            }
            if (! in_array($variable['source'] ?? null, self::SOURCES, true)) {
                BlockDefinitionError::fail("{$where}.source: use una de las fuentes del sistema (ver el manual técnico).");
            }
            $entry = ['label' => $variable['label'], 'source' => $variable['source']];
            foreach (['sample', 'equals'] as $text) {
                if (isset($variable[$text]) && $variable[$text] !== '') {
                    if (! is_string($variable[$text]) || mb_strlen($variable[$text]) > 500) {
                        BlockDefinitionError::fail("{$where}.{$text}: debe ser un texto de hasta 500 caracteres.");
                    }
                    $entry[$text] = $variable[$text];
                }
            }
            if (isset($variable['format'])) {
                if ($variable['format'] !== 'number') {
                    BlockDefinitionError::fail("{$where}.format: el único formato es «number».");
                }
                $entry['format'] = 'number';
            }
            $clean[$key] = $entry;
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    private static function sectionKeys(mixed $raw, string $where): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            BlockDefinitionError::fail("«{$where}» debe ser una lista de claves de sección.");
        }
        foreach ($raw as $key) {
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                BlockDefinitionError::fail("«{$where}»: «".(is_scalar($key) ? $key : '?').'» no es una clave de sección válida.');
            }
        }

        return array_values(array_unique($raw));
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array{key: string, label: string, field: string, quien_llena: string, content: array<string, mixed>}>
     */
    private static function definePresets(mixed $raw, array $sections): array
    {
        $fields = [];
        foreach ($sections as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fields[$field['key']] = $field;
            }
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            BlockDefinitionError::fail('«presets» debe ser una lista.');
        }
        $clean = [];
        foreach ($raw as $index => $preset) {
            if (! is_array($preset) || ! is_string($preset['key'] ?? null) || preg_match(self::KEY, $preset['key']) !== 1
                || ! is_string($preset['field'] ?? null) || preg_match(self::KEY, $preset['field']) !== 1
                || ! is_string($preset['label'] ?? null) || trim($preset['label']) === '' || mb_strlen($preset['label']) > 120) {
                BlockDefinitionError::fail("presets.{$index}: escriba key, label y field (la clave de un campo de la plantilla).");
            }
            $field = $fields[$preset['field']] ?? null;
            if ($field === null && isset($preset['content'], $preset['quien_llena'])) {
                $field = ['quien_llena' => $preset['quien_llena'], 'content' => $preset['content']];
            }
            if ($field === null) {
                BlockDefinitionError::fail("presets.{$index}.field: «{$preset['field']}» no es un campo de la plantilla.");
            }
            $clean[] = ['key' => $preset['key'], 'label' => $preset['label'], 'field' => $preset['field'], 'quien_llena' => (string) FieldDefinition::responsible($field['quien_llena']), 'content' => $field['content']];
        }

        return $clean;
    }
}
