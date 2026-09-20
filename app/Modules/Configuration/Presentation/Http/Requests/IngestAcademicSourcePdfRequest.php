<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use Illuminate\Validation\Rules\File;

final class IngestAcademicSourcePdfRequest extends ManageAcademicSourceRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'pdf' => [
                'required',
                File::types(['pdf'])->max((int) ceil((int) config('openparser.max_upload_bytes') / 1024)),
            ],
        ];
    }
}
