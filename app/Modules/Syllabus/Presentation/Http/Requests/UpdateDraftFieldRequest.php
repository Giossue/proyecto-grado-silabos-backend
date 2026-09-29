<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Configuration\Application\CurricularFieldOptions;
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
            // Hasta 52 semanas más cabeceras de unidad, con margen para otras listas.
            'rows' => ['nullable', 'array', 'max:250'],
            'rows.*.id' => ['nullable', 'uuid'],
            // Una celda por columna (I-34); `_unit` agrupa filas por unidad y `_kind`
            // marca la fila de cabecera de la unidad.
            'rows.*.data' => ['required_with:rows', 'array'],
            'rows.*.data.*' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('Cada celda debe ser un texto o un número.');

                    return;
                }
                if (is_string($value) && mb_strlen($value) > 10000) {
                    $fail('La celda supera los 10 000 caracteres.');
                }
            }],
        ];
        if ($field instanceof FieldDefinition && $field->tipo === 'seleccion_multiple') {
            $rules['value.*'] = ['string', 'distinct', Rule::in($this->allowedOptions($field))];
        }

        return $rules;
    }

    /** @return array{version_bloqueo: int, value?: mixed, rows?: list<array{id?: string|null, data: array<string, mixed>}>} */
    public function draftData(): array
    {
        $payload = ['version_bloqueo' => $this->integer('version_bloqueo')];
        if ($this->exists('value')) {
            $payload['value'] = $this->input('value');
        }

        $inputRows = $this->input('rows');
        if (is_array($inputRows)) {
            $rows = [];
            foreach ($inputRows as $inputRow) {
                if (! is_array($inputRow) || ! is_array($inputRow['data'] ?? null)) {
                    continue;
                }
                $row = ['data' => $inputRow['data']];
                if (is_string($inputRow['id'] ?? null)) {
                    $row['id'] = $inputRow['id'];
                }
                $rows[] = $row;
            }
            $payload['rows'] = $rows;
        }

        return $payload;
    }

    /** @return list<mixed> */
    private function valueRules(FieldDefinition $field): array
    {
        // El borrador puede guardarse incompleto; la obligatoriedad se comprueba al
        // validar o enviar el sílabo.
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
            // Texto libre (I-156): documento con párrafos, listas y tablas simples.
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
            'repetible' => ['prohibited'],
            'referencia_maestra', 'calculo' => ['nullable'],
            default => ['prohibited'],
        };
    }

    /** @return list<string> */
    private function allowedOptions(FieldDefinition $field): array
    {
        $syllabus = $this->route('syllabus');

        return CurricularFieldOptions::values($field, $syllabus instanceof Syllabus ? ($syllabus->contexto_academico ?? []) : []);
    }
}
