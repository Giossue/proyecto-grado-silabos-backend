<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\FieldDocument;
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
     * @return array<string, mixed>
     */
    public static function build(TemplateSection $section, Closure $stored, array $context): array
    {
        $variables = TemplateVariables::resolve($context);

        return [
            'id' => $section->id,
            'key' => $section->clave,
            'title' => $section->titulo,
            'orientation' => $section->orientacion,
            'fields' => $section->blocks->flatMap(fn (TemplateBlock $block) => $block->fields->map(function (FieldDefinition $field) use ($stored, $variables): array {
                $document = FieldDocument::normalize($field->quien_llena === FieldDefinition::INSTITUTIONAL ? $field->contenido : $stored($field));

                return [
                    'id' => $field->id,
                    'key' => $field->clave,
                    'label' => $field->etiqueta,
                    'quien_llena' => $field->quien_llena,
                    ...(FieldResponse::enabled() && $field->quien_llena !== FieldDefinition::INSTITUTIONAL ? ['template' => FieldResponse::template(FieldDocument::normalize($field->contenido))] : []),
                    'teacher_editable' => $field->teacherEditable(),
                    'required' => $field->quien_llena !== FieldDefinition::INSTITUTIONAL,
                    'value' => $document,
                    'document' => FieldDocument::resolve($document, $variables),
                ];
            })->all())->values()->all(),
        ];
    }
}
