<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Configuration\Application\FieldOptions;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateDraftFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $syllabus = $this->route('syllabus');

        return $syllabus instanceof Syllabus && $this->user()?->can('edit', $syllabus) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $field = $this->route('field');
        $valueRules = $field instanceof FieldDefinition
            ? $this->valueRules($field)
            : ['prohibited'];

        $rules = [
            'version_bloqueo' => ['required', 'integer', 'min:0'],
            'value' => $valueRules,
        ];
        if ($field instanceof FieldDefinition && $field->tipo === 'seleccion_multiple') {
            $rules['value.*'] = ['string', 'distinct', Rule::in($this->allowedOptions($field))];
        }

        return $rules;
    }

    /** @return array{version_bloqueo: int, value?: mixed} */
    public function draftData(): array
    {
        $payload = ['version_bloqueo' => $this->integer('version_bloqueo')];
        if ($this->exists('value')) {
            $payload['value'] = $this->input('value');
        }

        return $payload;
    }

    /** @return list<mixed> */
    private function valueRules(FieldDefinition $field): array
    {
        $presence = 'nullable';

        return match ($field->tipo) {
            'texto_corto' => [$presence, 'string', 'max:1000'],
            'texto_largo' => [
                $presence,
                'string',
                'max:50000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && preg_match('/<\/?[a-z][^>]*>/i', $value) === 1) {
                        $fail('Use texto; el HTML no está permitido.');
                    }
                },
            ],
            'texto_libre' => [
                $presence,
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        FreeText::normalize($value, $attribute);
                    } catch (ValidationException $exception) {
                        $fail((string) collect($exception->errors())->flatten()->first());
                    }
                },
            ],
            'numero' => [$presence, 'numeric'],
            'fecha' => [$presence, 'date_format:Y-m-d'],
            'seleccion_unica' => [$presence, 'string', Rule::in($this->allowedOptions($field))],
            'seleccion_multiple' => [$presence, 'array', 'list', 'max:50'],
            'booleano' => [$presence, 'boolean'],
            'referencia_maestra', 'calculo' => ['nullable'],
            default => ['prohibited'],
        };
    }

    /** @return list<string> */
    private function allowedOptions(FieldDefinition $field): array
    {
        return FieldOptions::values($field);
    }
}
