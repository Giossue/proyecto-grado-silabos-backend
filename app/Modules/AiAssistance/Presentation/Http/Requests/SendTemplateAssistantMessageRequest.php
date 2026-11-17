<?php

namespace App\Modules\AiAssistance\Presentation\Http\Requests;

use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\ResponseCondition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Presentation\Http\Requests\ManageTemplatesRequest;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SendTemplateAssistantMessageRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.config('ai.limits.chat_message_characters')],
            'idempotency_key' => ['required', 'uuid'],
            'draft_version' => ['required', 'string', 'max:100'],
            'draft' => ['present', 'array', 'max:60'],
            'draft.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100', 'distinct'],
            'draft.*.title' => ['present', 'nullable', 'string', 'max:180'],
            'draft.*.orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'draft.*.fields' => ['present', 'array', 'max:60'],
            'draft.*.fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100'],
            'draft.*.fields.*.label' => ['present', 'nullable', 'string', 'max:180'],
            'draft.*.fields.*.quien_llena' => ['required', Rule::in(FieldDefinition::RESPONSIBLES)],
            'draft.*.fields.*.content' => ['required', 'array', $this->document()],
            'draft.*.fields.*.show_if' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'message' => 'pedido',
            'idempotency_key' => 'clave del pedido',
            'draft' => 'borrador',
            'draft_version' => 'versión del borrador',
            'draft.*.key' => 'clave de la sección',
            'draft.*.title' => 'título de la sección',
            'draft.*.orientation' => 'orientación',
            'draft.*.fields' => 'campos',
            'draft.*.fields.*.key' => 'clave del campo',
            'draft.*.fields.*.label' => 'nombre del campo',
            'draft.*.fields.*.quien_llena' => 'quién lo llena',
            'draft.*.fields.*.content' => 'contenido',
            'draft.*.fields.*.show_if' => 'mostrar solo si',
        ];
    }

    /**
     * @return list<array{key: string, title: string, orientation: string, fields: list<array{key: string, label: string, quien_llena: string, content: array<string, mixed>, show_if?: array{response: string, options: list<string>}}>}>
     */
    public function draft(): array
    {
        $sections = [];
        foreach ((array) $this->validated('draft') as $section) {
            $fields = [];
            foreach ((array) $section['fields'] as $field) {
                $fields[] = [
                    'key' => (string) $field['key'],
                    'label' => trim((string) ($field['label'] ?? '')),
                    'quien_llena' => (string) $field['quien_llena'],
                    'content' => $this->normalized((array) $field['content']),
                    ...$this->condition($field['show_if'] ?? null),
                ];
            }
            $sections[] = [
                'key' => (string) $section['key'],
                'title' => trim((string) ($section['title'] ?? '')),
                'orientation' => (string) $section['orientation'],
                'fields' => $fields,
            ];
        }

        return $sections;
    }

    /**
     * @return array{show_if?: array{response: string, options: list<string>}}
     */
    private function condition(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        try {
            return ['show_if' => ResponseCondition::define($raw)];
        } catch (ValidationException) {
            return [];
        }
    }

    private function document(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                $this->normalized(is_array($value) ? $value : []);
            } catch (ValidationException) {
                $fail('El borrador tiene un contenido que no se puede leer. Guárdelo o recargue la página.');
            }
        };
    }

    /**
     * @param  array<mixed>  $content
     * @return array<string, mixed>
     */
    private function normalized(array $content): array
    {
        $definition = SyllabusTemplate::query()->firstOrFail()->definition();

        return app(TemplateContext::class)->using($definition, fn (): array => FieldDocument::normalize($content));
    }
}
