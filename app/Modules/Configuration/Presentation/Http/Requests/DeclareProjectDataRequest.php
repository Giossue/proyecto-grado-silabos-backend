<?php

namespace App\Modules\Configuration\Presentation\Http\Requests;

class DeclareProjectDataRequest extends ManageTemplatesRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:180', 'regex:/[\pL\pN]/u'],
            'type' => ['required', 'in:texto,lista'],
            // Todo sílabo es de una carrera y una asignatura: por omisión, cada asignatura tiene el suyo.
            'scope' => ['sometimes', 'in:asignatura,carrera'],
        ];
    }
}
