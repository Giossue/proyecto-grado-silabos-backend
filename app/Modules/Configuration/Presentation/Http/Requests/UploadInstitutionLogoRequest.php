<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

use App\Modules\Configuration\Application\InstitutionalLogos;

class UploadInstitutionLogoRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['logo' => ['required', ...InstitutionalLogos::rules(InstitutionalLogos::INSTITUTION)]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['logo' => 'logo'];
    }
}
