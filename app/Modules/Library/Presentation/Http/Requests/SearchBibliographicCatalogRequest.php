<?php

namespace App\Modules\Library\Presentation\Http\Requests;

use App\Modules\Library\Domain\Contracts\BibliographicCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SearchBibliographicCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('search-library-catalog');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::in(BibliographicCatalog::FIELDS)],
            'q' => ['required', 'string', 'min:2', 'max:'.(int) config('catalog.limits.term_characters')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['field' => 'campo de búsqueda', 'q' => 'texto a buscar'];
    }
}
