<?php

namespace App\Modules\Library\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ViewCatalogLibrariesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-academic-governance');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
