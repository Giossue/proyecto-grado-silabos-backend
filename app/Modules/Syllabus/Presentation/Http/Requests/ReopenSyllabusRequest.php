<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReopenSyllabusRequest extends FormRequest
{
    public function authorize(): Response
    {
        $syllabus = $this->route('syllabus');
        if (! $syllabus instanceof Syllabus) {
            return Response::deny();
        }

        return Gate::forUser($this->user())->inspect('decide', $syllabus);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'cause' => ['required', 'string', 'min:10', 'max:10000'],
        ];
    }
}
