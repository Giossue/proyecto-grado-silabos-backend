<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ResetSyllabusRequest extends FormRequest
{
    public function authorize(): Response
    {
        $syllabus = $this->route('syllabus');
        if (! $syllabus instanceof Syllabus) {
            return Response::deny();
        }

        return Gate::forUser($this->user())->inspect('reset', $syllabus);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // Se descarta trabajo ajeno: el motivo tiene que quedar escrito.
        return ['reason' => ['required', 'string', 'min:10', 'max:2000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Indique el motivo del reinicio.',
            'reason.min' => 'El motivo debe explicar por qué se descarta el trabajo, no solo nombrarlo.',
        ];
    }
}
