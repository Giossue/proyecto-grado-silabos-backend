<?php

namespace App\Modules\Library\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class VerifyLibraryReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review-library');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
