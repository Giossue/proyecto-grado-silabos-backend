<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldLimit;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use Closure;

/** Una sección de la plantilla tal como se ve en el sílabo: sus campos con el valor guardado y las variables resueltas. */
final class SyllabusSectionPayload
{
    /**
     * @param  Closure(FieldDefinition): mixed  $stored  valor guardado del campo (sin variables resueltas)
     * @param  array<string, mixed>  $context
     * @param  array<string, array<string, mixed>>  $templates  plantilla de cada campo de Carrera en la materia, por definición de campo
     * @return array<string, mixed>
     */
    public static function build(TemplateSection $section, Closure $stored, array $context, array $templates = []): array
    {
        $variables = TemplateVariables::resolve($context);
        $conditions = TemplateContext::active()->fieldConditions();
        $limits = TemplateContext::active()->fieldLimits();

        return [
            'id' => $section->id,
            'key' => $section->clave,
            'title' => $section->titulo,
            'orientation' => $section->orientacion,
            'fields' => $section->blocks->flatMap(fn (TemplateBlock $block) => $block->fields->map(function (FieldDefinition $field) use ($conditions, $limits, $stored, $templates, $variables): array {
                $document = FieldDocument::normalize($field->quien_llena === FieldDefinition::INSTITUTIONAL ? $field->contenido : $stored($field));
                $editable = SubjectProfileFields::teacherEditable($field, $templates);
                $template = FieldDocument::normalize($templates[$field->id] ?? $field->contenido);

                return [
                    'id' => $field->id,
                    'key' => $field->clave,
                    'label' => $field->etiqueta,
                    'quien_llena' => $field->quien_llena,
                    ...(FieldResponse::enabled() && $field->quien_llena !== FieldDefinition::INSTITUTIONAL ? ['template' => $editable ? FieldResponse::template($template) : $template] : []),
                    'teacher_editable' => $editable,
                    'required' => $field->quien_llena !== FieldDefinition::INSTITUTIONAL,
                    'value' => $document,
                    'document' => FieldDocument::resolve($document, $variables),
                    ...(isset($conditions[$field->clave]) ? ['show_if' => $conditions[$field->clave]] : []),
                    ...(isset($limits[$field->clave]) && FieldLimit::allowed($field->quien_llena) ? ['max_length' => $limits[$field->clave]] : []),
                ];
            })->all())->values()->all(),
        ];
    }
}
