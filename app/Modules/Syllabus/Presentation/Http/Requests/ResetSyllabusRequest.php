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
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }
}
