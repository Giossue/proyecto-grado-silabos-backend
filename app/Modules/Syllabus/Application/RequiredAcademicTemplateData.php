<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;

/** Los datos curriculares solo se exigen cuando el formato los utiliza. */
final class RequiredAcademicTemplateData
{
    /**
     * @param  array<string, mixed>  $academicContext
     * @return list<array{field_id: null, code: string, severity: string, message: string}>
     */
    public function issues(array $academicContext): array
    {
        $definitions = TemplateVariables::definitions();
        $values = TemplateVariables::resolve(['academic_context' => $academicContext]);
        $missing = [];

        foreach (SyllabusTemplate::institutional()->sections as $section) {
            foreach ($section->blocks as $block) {
                $document = $block->configuracion['document'] ?? null;
                if (! is_array($document)) {
                    continue;
                }
                foreach (TemplateDocument::nodes($document, 'variable') as $node) {
                    $key = $node['attrs']['id'] ?? null;
                    if (! is_string($key) || ! ($definitions[$key]['required'] ?? false) || trim($values[$key] ?? '') !== '') {
                        continue;
                    }
                    $label = $definitions[$key]['label'];
                    $missing[$key] = [
                        'field_id' => null,
                        'code' => 'dato_curricular_faltante',
                        'severity' => 'error',
                        'message' => "Avise a Coordinación: faltan «{$label}» validados en la información de esta asignatura. Coordinación debe completar la base académica y actualizar el sílabo mediante el procedimiento de corrección.",
                    ];
                }
            }
        }

        return array_values($missing);
    }
}
