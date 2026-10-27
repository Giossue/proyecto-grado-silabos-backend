<?php

namespace App\Modules\Library\Presentation\Http\Requests;

use App\Modules\Library\Application\LibraryReviews;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ViewLibraryReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review-library');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', Rule::in(LibraryReviews::STATES)],
            'q' => ['nullable', 'string', 'max:100'],
            'faculty' => ['nullable', 'uuid', Rule::exists('facultades', 'facultad_id')],
            'period' => ['nullable', 'uuid', Rule::exists('periodos_academicos', 'periodo_academico_id')],
            'mine' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['faculty' => 'facultad', 'period' => 'período', 'mine' => 'solo los míos'];
    }
}
