<?php

namespace App\Modules\Academic\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSubjectProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->activo === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'descripcion' => ['present', 'nullable', 'string'],
            'objetivo_general' => ['present', 'nullable', 'string'],
            'relacion_perfil_egreso' => ['present', 'nullable', 'string'],
        ];
        foreach (['objetivos_especificos', 'resultados_aprendizaje', 'bibliografia_basica', 'bibliografia_complementaria', 'herramientas_tic'] as $key) {
            $rules[$key] = ['present', 'nullable', 'array', 'list'];
            $rules[$key.'.*'] = ['required', 'string', 'filled'];
        }
        foreach (['habilidades_blandas' => ['nombre', 'definicion'], 'herramientas_ia' => ['herramienta', 'caracteristica']] as $key => $fields) {
            $rules[$key] = ['present', 'nullable', 'array', 'list'];
            $rules[$key.'.*'] = ['required', 'array:'.implode(',', $fields)];
            foreach ($fields as $field) {
                $rules[$key.'.*.'.$field] = ['required', 'string', 'filled'];
            }
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'descripcion' => 'descripción de la asignatura',
            'objetivo_general' => 'objetivo principal de la materia',
            'relacion_perfil_egreso' => 'relación con el perfil de egreso',
            'objetivos_especificos' => 'objetivos específicos',
            'objetivos_especificos.*' => 'objetivo específico',
            'resultados_aprendizaje' => 'resultados de aprendizaje',
            'resultados_aprendizaje.*' => 'resultado de aprendizaje',
            'bibliografia_basica' => 'bibliografía básica',
            'bibliografia_basica.*' => 'referencia básica',
            'bibliografia_complementaria' => 'bibliografía complementaria',
            'bibliografia_complementaria.*' => 'referencia complementaria',
            'herramientas_tic' => 'herramientas TIC',
            'herramientas_tic.*' => 'herramienta TIC',
            'habilidades_blandas' => 'lista de habilidades blandas',
            'habilidades_blandas.*' => 'habilidad blanda',
            'habilidades_blandas.*.nombre' => 'nombre de la habilidad',
            'habilidades_blandas.*.definicion' => 'definición de la habilidad',
            'herramientas_ia' => 'herramientas de IA',
            'herramientas_ia.*' => 'herramienta de IA',
            'herramientas_ia.*.herramienta' => 'nombre de la herramienta de IA',
            'herramientas_ia.*.caracteristica' => 'característica de la herramienta de IA',
        ];
    }
}
