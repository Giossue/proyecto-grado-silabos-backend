<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\ResponseCondition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;

final class SyllabusConditions
{
    /**
     * @param  array<string, array<string, mixed>>  $templates
     * @return array{hidden: array<string, true>, answers: array<string, mixed>}
     */
    public static function evaluate(Syllabus $syllabus, array $templates): array
    {
        $values = $syllabus->values()->get()->keyBy('definicion_campo_id');
        $fields = [];
        $ids = [];
        foreach (SyllabusTemplate::institutional()->sections as $section) {
            foreach ($section->blocks as $block) {
                foreach ($block->fields as $field) {
                    $stored = $values->get($field->id);
                    $fields[] = ['key' => $field->clave, 'document' => match (true) {
                        $field->quien_llena === FieldDefinition::INSTITUTIONAL => $field->contenido,
                        $stored instanceof FieldValue => $stored->valor,
                        default => $templates[$field->id] ?? $field->contenido,
                    }];
                    $ids[$field->clave] = (string) $field->id;
                }
            }
        }
        $result = ResponseCondition::evaluate($fields, TemplateContext::active()->fieldConditions());
        $hidden = [];
        foreach (array_keys($result['hidden']) as $key) {
            if (isset($ids[$key])) {
                $hidden[$ids[$key]] = true;
            }
        }

        return ['hidden' => $hidden, 'answers' => $result['answers']];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, array{response: string, options: list<string>}>  $conditions
     * @return list<array<string, mixed>>
     */
    public static function visibleSections(array $sections, array $conditions): array
    {
        $fields = [];
        foreach ($sections as $section) {
            foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                $fields[] = ['key' => (string) ($field['key'] ?? ''), 'document' => $field['document'] ?? null];
            }
        }
        if ($conditions === [] && array_filter($fields, fn (array $field): bool => array_filter(ResponseCondition::responses($field['document']), fn (array $attrs): bool => isset($attrs[ResponseCondition::ATTR])) !== []) === []) {
            return $sections;
        }
        $result = ResponseCondition::evaluate($fields, $conditions);
        $visible = [];
        foreach ($sections as $section) {
            $kept = [];
            $all = is_array($section['fields'] ?? null) ? $section['fields'] : [];
            foreach ($all as $field) {
                if (isset($result['hidden'][(string) ($field['key'] ?? '')])) {
                    continue;
                }
                if (isset($field['document'])) {
                    $field['document'] = ResponseCondition::withoutHiddenLines(ResponseCondition::clear($field['document'], $result['answers']), $result['answers']);
                }
                $kept[] = $field;
            }
            if ($kept === [] && $all !== []) {
                continue;
            }
            $visible[] = [...$section, 'fields' => $kept];
        }

        return $visible;
    }
}
