<?php

namespace App\Modules\Library\Presentation\Http\Requests;

use App\Modules\Library\Application\LibraryCommentReasons;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CommentLibraryReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review-library');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['string', 'distinct', Rule::in(array_keys(LibraryCommentReasons::REASONS))],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reasons.required' => 'Elija al menos un motivo.',
            'reasons.min' => 'Elija al menos un motivo.',
            'reasons.*.in' => 'El motivo elegido no es válido.',
        ];
    }
}
