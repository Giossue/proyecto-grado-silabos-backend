<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Application\Actions\TransitionSyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Foundation\Http\FormRequest;

class TransitionSyllabusProcessRequest extends FormRequest
{
    /** @var array<string, string> */
    private const ABILITIES = [
        TransitionSyllabusProcess::PAUSE => 'pause',
        TransitionSyllabusProcess::RESUME => 'resume',
        TransitionSyllabusProcess::CLOSE => 'close',
    ];

    public function authorize(): bool
    {
        $process = $this->route('process');
        $ability = self::ABILITIES[$this->transition()] ?? null;

        return $process instanceof SyllabusProcess
            && $ability !== null
            && $this->user()?->can($ability, $process) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // Pausar detiene a toda la universidad: el motivo tiene que quedar escrito.
        return $this->transition() === TransitionSyllabusProcess::PAUSE
            ? ['reason' => ['required', 'string', 'min:10', 'max:500']]
            : ['reason' => ['nullable', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indique el motivo de la pausa.',
            'reason.min' => 'Escriba al menos 10 caracteres para explicar la pausa.',
        ];
    }

    public function transition(): string
    {
        $transition = $this->route('transition');

        return is_string($transition) ? $transition : '';
    }
}
