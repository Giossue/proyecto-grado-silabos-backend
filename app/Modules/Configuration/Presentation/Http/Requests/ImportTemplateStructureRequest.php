<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Domain\TemplateStyle;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ImportTemplateStructureRequest extends ManageTemplatesRequest
{
    public const FORMAT_VERSION = 2;

    private ?TemplateDefinition $definition = null;

    /** @var list<string>|null */
    private ?array $definitionErrors = null;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $choices = (SyllabusTemplate::query()->first()?->definition() ?? TemplateContext::active())->document()['choices'];

        return [
            'version' => ['required', 'integer', Rule::in([1, self::FORMAT_VERSION])],
            'title' => ['sometimes', 'nullable', 'string', 'max:180'],
            'layout' => ['sometimes', 'array'],
            'layout.margin_cm' => ['sometimes', 'numeric', Rule::in($choices['margin_cm'])],
            'layout.title_font_size' => ['sometimes', 'integer', Rule::in($choices['title'])],
            'layout.section_font_size' => ['sometimes', 'integer', Rule::in($choices['section'])],
            'layout.body_font_size' => ['sometimes', 'integer', Rule::in($choices['body'])],
            'lists' => ['sometimes', 'array', 'max:20'],
            'lists.*.block' => ['required', 'string', 'max:40'],
            'lists.*.path' => ['required', 'string', 'max:200'],
            'lists.*.min' => ['required', 'integer', 'min:0', 'max:100'],
            'lists.*.max' => ['required', 'integer', 'min:1', 'max:100'],
            'lists.*.initial' => ['required', 'integer', 'min:0', 'max:100'],
            ...array_fill_keys(TemplateDefinition::ROOT_KEYS, ['sometimes', 'array']),
            'response_policy' => ['sometimes', 'in:protected'],
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
                $definition = $this->validDefinition();
                if ($definition === null) {
                    return;
                }
                try {
                    app(TemplateContext::class)->using($definition, fn (): array => FieldDocument::normalize($value, $attribute));
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
            'lists' => 'listas',
            'lists.*.block' => 'bloque de la lista',
            'lists.*.path' => 'lista',
            'lists.*.min' => 'mínimo',
            'lists.*.max' => 'máximo',
            'lists.*.initial' => 'al empezar',
            'document' => 'documento',
            'variables' => 'datos del sistema',
            'blocks' => 'bloques',
            'response_policy' => 'política de respuestas',
            'required_sections' => 'secciones obligatorias',
            'library_sections' => 'secciones de biblioteca',
            'presets' => 'plantillas listas',
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

    /**
     * @return list<Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->validDefinition() === null) {
                foreach ($this->definitionErrors ?? [] as $message) {
                    $validator->errors()->add('definition', $message);
                }
            }
        }];
    }

    private function validDefinition(): ?TemplateDefinition
    {
        if ($this->definitionErrors !== null) {
            return null;
        }
        try {
            return $this->definition();
        } catch (ValidationException $exception) {
            $this->definitionErrors = array_values(array_map('strval', collect($exception->errors())->flatten()->all()));

            return null;
        }
    }

    public function definition(): TemplateDefinition
    {
        if ($this->definition !== null) {
            return $this->definition;
        }
        $current = SyllabusTemplate::query()->first()?->definition() ?? TemplateContext::active();
        if ((int) $this->input('version') !== self::FORMAT_VERSION) {
            return $this->definition = $this->defining(fn (): TemplateDefinition => $this->withTitleAndLayout($current));
        }
        $sections = [];
        foreach ((array) $this->input('sections', []) as $section) {
            $fields = [];
            foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                $content = $field['content'] ?? null;
                $fields[] = [
                    'key' => $field['key'] ?? null,
                    'quien_llena' => $field['quien_llena'] ?? null,
                    'content' => is_string($content) || (is_array($content) && array_is_list($content)) ? DocumentHtml::fromHtml($content) : $content,
                ];
            }
            $sections[] = ['key' => $section['key'] ?? null, 'fields' => $fields];
        }
        $definition = $this->defining(fn (): TemplateDefinition => TemplateDefinition::define($this->only(TemplateDefinition::ROOT_KEYS), $sections));
        $keys = array_column($sections, 'key');
        foreach (['required_sections' => $definition->requiredSections(), 'library_sections' => $definition->librarySections()] as $name => $required) {
            $missing = array_diff($required, $keys);
            if ($missing !== []) {
                throw ValidationException::withMessages(['definition' => "«{$name}» nombra secciones que no están en la plantilla: ".implode(', ', $missing).'.']);
            }
        }

        return $this->definition = $this->defining(fn (): TemplateDefinition => $this->withTitleAndLayout($definition));
    }

    private function withTitleAndLayout(TemplateDefinition $definition): TemplateDefinition
    {
        $document = $definition->document();
        $title = $this->input('title');
        if (is_string($title) && trim($title) !== '') {
            $document['title']['text'] = trim($title);
        }
        $layout = $this->input('layout');
        $lists = $this->input('lists');
        if (is_array($lists) && $lists !== []) {
            $definition = $definition->withListLimits(array_map(fn (mixed $list): array => [
                'block' => (string) ($list['block'] ?? ''), 'path' => (string) ($list['path'] ?? ''),
                'min' => (int) ($list['min'] ?? 0), 'max' => (int) ($list['max'] ?? 0), 'initial' => (int) ($list['initial'] ?? 0),
            ], array_values(array_filter($lists, 'is_array'))));
        }

        return $definition->withDocument(is_array($layout) ? TemplateStyle::withLayout($document, array_intersect_key($layout, array_flip(['margin_cm', 'title_font_size', 'section_font_size', 'body_font_size']))) : $document);
    }

    /**
     * @param  Closure(): TemplateDefinition  $callback
     */
    private function defining(Closure $callback): TemplateDefinition
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['definition' => collect($exception->errors())->flatten()->all()]);
        }
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
