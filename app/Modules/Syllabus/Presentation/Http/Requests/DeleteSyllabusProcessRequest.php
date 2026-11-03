<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Foundation\Http\FormRequest;

class DeleteSyllabusProcessRequest extends FormRequest
{
    public const MESSAGE_MAX = 1000;

    public function authorize(): bool
    {
        $process = $this->route('process');

        return $process instanceof SyllabusProcess
            && $this->user()?->can('delete', $process) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'mensaje' => ['required', 'string', 'max:'.self::MESSAGE_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mensaje.required' => 'Escriba el mensaje para docentes, coordinación y biblioteca.',
            'mensaje.string' => 'Escriba el mensaje para docentes, coordinación y biblioteca.',
            'mensaje.max' => 'El mensaje no puede pasar de '.self::MESSAGE_MAX.' caracteres.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['mensaje' => 'mensaje para docentes, coordinación y biblioteca'];
    }

    public function notice(): string
    {
        return trim($this->string('mensaje')->toString());
    }
}
