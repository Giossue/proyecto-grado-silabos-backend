<?php

namespace App\Modules\Library\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CommentLibraryReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review-library');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['comment.required' => 'Escriba qué libros faltan o qué debe cambiar el docente.'];
    }
}
