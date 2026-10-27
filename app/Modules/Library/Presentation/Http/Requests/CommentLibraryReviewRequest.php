<?php

namespace App\Modules\Library\Presentation\Http\Requests;

use App\Modules\Library\Application\LibraryCommentReasons;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CommentLibraryReviewRequest extends FormRequest
{
    public function authorize(): Response
    {
        $revision = $this->route('revision');
        if (! Gate::allows('review-library') || ! $revision instanceof SyllabusRevision) {
            return Response::deny();
        }

        return Gate::forUser($this->user())->inspect('decide', $revision);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reasons' => ['required', 'array', 'min:1'],
            'reasons.*' => ['string', 'distinct', Rule::in(array_keys(LibraryCommentReasons::REASONS))],
            'other' => [Rule::requiredIf(fn (): bool => in_array(LibraryCommentReasons::OTHER, (array) $this->input('reasons', []), true)), 'nullable', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reasons.required' => 'Elija al menos un motivo.',
            'reasons.min' => 'Elija al menos un motivo.',
            'reasons.*.in' => 'El motivo elegido no es válido.',
            'other.required' => 'Escriba el otro motivo.',
            'other.min' => 'Escriba el otro motivo con al menos 5 caracteres.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reasons' => 'motivos', 'reasons.*' => 'motivo', 'other' => 'otro motivo'];
    }
}
