<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportTemplateStructureRequest extends ManageTemplatesRequest
{
    public const FORMAT_VERSION = 1;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', Rule::in([self::FORMAT_VERSION])],
            'title' => ['sometimes', 'nullable', 'string', 'max:'.TemplateTitleBlock::MAX_LENGTH],
            'layout' => ['sometimes', 'array'],
            'layout.margin_cm' => ['sometimes', 'numeric', Rule::in(TemplateAppearance::MARGINS)],
            'layout.title_font_size' => ['sometimes', 'integer', Rule::in(TemplateAppearance::TITLE_SIZES)],
            'layout.section_font_size' => ['sometimes', 'integer', Rule::in(TemplateAppearance::SECTION_SIZES)],
            'layout.body_font_size' => ['sometimes', 'integer', Rule::in(TemplateAppearance::BODY_SIZES)],
            'sections' => ['present', 'array', 'max:60'],
            'sections.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100', 'distinct'],
            'sections.*.title' => ['required', 'string', 'max:180'],
            'sections.*.orientation' => ['sometimes', Rule::in(['portrait', 'landscape'])],
            'sections.*.fields' => ['sometimes', 'array', 'max:60'],
            'sections.*.fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100', $this->uniqueFieldKey()],
            'sections.*.fields.*.label' => ['nullable', 'string', 'max:180'],
            'sections.*.fields.*.quien_llena' => ['required', Rule::in(FieldDefinition::RESPONSIBLES)],
            'sections.*.fields.*.content' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) || (is_array($value) && array_is_list($value) && $value !== [] && array_filter($value, 'is_string') === $value)) {
                    $value = DocumentHtml::fromHtml($value);
                } elseif (! is_array($value) || array_is_list($value)) {
                    $fail('El contenido debe ser un documento, un texto HTML o una lista de líneas HTML.');

                    return;
                }
                try {
                    FieldDocument::normalize($value, $attribute);
                } catch (ValidationException $exception) {
                    foreach (collect($exception->errors())->flatten() as $message) {
                        $fail((string) $message);
                    }
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'version' => 'versión',
            'title' => 'título del documento',
            'layout' => 'diseño',
            'layout.margin_cm' => 'margen',
            'layout.title_font_size' => 'tamaño del título',
            'layout.section_font_size' => 'tamaño de las secciones',
            'layout.body_font_size' => 'tamaño del contenido',
            'sections' => 'secciones',
            'sections.*.key' => 'clave de la sección',
            'sections.*.title' => 'título de la sección',
            'sections.*.orientation' => 'orientación',
            'sections.*.fields' => 'campos',
            'sections.*.fields.*.key' => 'clave del campo',
            'sections.*.fields.*.label' => 'nombre del campo',
            'sections.*.fields.*.quien_llena' => 'quién lo llena',
            'sections.*.fields.*.content' => 'contenido',
        ];
    }

    private function uniqueFieldKey(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $keys = [];
            foreach ((array) $this->input('sections', []) as $section) {
                foreach ((array) ($section['fields'] ?? []) as $field) {
                    $keys[] = is_array($field) ? ($field['key'] ?? null) : null;
                }
            }
            if (count(array_keys($keys, $value, true)) > 1) {
                $fail('La clave del campo «'.$value.'» está repetida; cada campo necesita una clave única en toda la plantilla.');
            }
        };
    }
}
