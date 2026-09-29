<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Academic\Application\CurricularData;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;

final class CurricularFieldOptions
{
    public const CHOICE_TYPES = ['seleccion_unica', 'seleccion_multiple'];

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function fromAttributes(array $data): array
    {
        $sources = $data['options_sources'] ?? null;
        if (is_array($sources)) {
            return array_values(array_unique(array_filter($sources, is_string(...))));
        }
        $source = $data['options_source'] ?? null;

        return is_string($source) ? [$source] : [];
    }

    /** @return list<string> */
    public static function sources(FieldDefinition $field): array
    {
        $configuration = $field->block->configuracion ?? [];
        $document = $configuration['document'] ?? null;
        if (is_array($document)) {
            foreach (TemplateDocument::nodes($document, 'field') as $node) {
                if (($node['attrs']['key'] ?? null) === $field->clave) {
                    return self::fromAttributes($node['attrs']);
                }
            }
        }
        $source = $configuration['options_sources'][$field->clave] ?? null;

        return is_array($source) ? self::fromAttributes(['options_sources' => $source])
            : self::fromAttributes(['options_source' => $source]);
    }

    public static function source(FieldDefinition $field): ?string
    {
        $sources = self::sources($field);

        return count($sources) === 1 ? $sources[0] : null;
    }

    public static function sourceIsAllowed(string $source): bool
    {
        return str_starts_with($source, CurricularData::VARIABLE_PREFIX)
            && (TemplateVariables::definitions()[$source]['type'] ?? null) === 'lista';
    }

    /**
     * @param  array<string, mixed>  $academicContext
     * @return list<array{value: string, label: string}>
     */
    public static function options(FieldDefinition $field, array $academicContext = []): array
    {
        $sources = self::sources($field);
        $options = $sources === [] ? ($field->opciones ?? []) : [];
        foreach ($sources as $source) {
            $key = substr($source, strlen(CurricularData::VARIABLE_PREFIX));
            $raw = data_get($academicContext, 'curricular.values.'.$key);
            if (! str_starts_with($source, CurricularData::VARIABLE_PREFIX)
                || data_get($academicContext, 'curricular.definitions.'.$key.'.type') !== 'lista'
                || ! is_array($raw) || $raw === []) {
                return [];
            }
            $options = [...$options, ...$raw];
        }
        $normalized = [];
        foreach ($options as $option) {
            if (is_string($option) || is_int($option)) {
                $value = (string) $option;
                $label = $value;
            } elseif (is_array($option) && (is_string($option['value'] ?? null) || is_int($option['value'] ?? null))) {
                $value = (string) $option['value'];
                $label = is_string($option['label'] ?? null) ? $option['label'] : $value;
            } else {
                continue;
            }
            if (trim($value) !== '' && ! isset($normalized[$value])) {
                $normalized[$value] = ['value' => $value, 'label' => $label];
            }
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $academicContext
     * @return list<string>
     */
    public static function values(FieldDefinition $field, array $academicContext = []): array
    {
        return array_column(self::options($field, $academicContext), 'value');
    }
}
