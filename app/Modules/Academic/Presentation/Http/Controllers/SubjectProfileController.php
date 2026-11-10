<?php

namespace App\Modules\Academic\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Application\Actions\SaveSubjectProfile;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Presentation\Http\Requests\SaveSubjectProfileRequest;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Application\TemplatePresentation;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Syllabus\Application\AcademicContextSnapshot;
use App\Modules\Syllabus\Application\IdentificationCard;
use App\Modules\Syllabus\Application\SyllabusSectionPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectProfileController extends Controller
{
    public function show(Subject $asignatura, Request $request, SaveSubjectProfile $action, SubjectProfileFields $profile): JsonResponse
    {
        $action->authorize($asignatura, $request);

        return response()->json($this->payload($asignatura->id, $profile->values($asignatura->id), $profile));
    }

    public function update(Subject $asignatura, SaveSubjectProfileRequest $request, SaveSubjectProfile $action, SubjectProfileFields $profile): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /**
         * @var array<string, mixed> $values
         */
        $values = $request->validated('values');

        return response()->json($this->payload($asignatura->id, $action->execute($asignatura, $values, $actor, $request), $profile));
    }

    /**
     * Cómo se verá el sílabo de la materia antes de que el docente lo llene: datos de la malla y lo guardado en el
     * proyecto de carrera; el resto queda como en la plantilla. Mismo criterio que InheritMasterValues al generar el sílabo.
     */
    public function preview(Subject $asignatura, Request $request, SaveSubjectProfile $action, SubjectProfileFields $profile, AcademicContextSnapshot $context, InstitutionalLogos $logos): JsonResponse
    {
        $action->authorize($asignatura, $request);
        $values = $profile->values($asignatura->id);
        $academic = $context->forSubject($asignatura);
        $identification = IdentificationCard::build($academic, [], [], []);
        $template = SyllabusTemplate::institutional();
        $setups = $profile->setups($asignatura->id);
        $stored = function (FieldDefinition $field) use ($setups, $values): mixed {
            if ($field->teacherEditable()) {
                return $setups[$field->id] ?? $field->contenido;
            }
            $inherited = $field->fromProfile() ? ($values[$field->id] ?? null) : null;

            return $inherited === null || FieldDocument::isEmpty($inherited) ? $field->contenido : $inherited;
        };

        return response()->json([
            'presentation' => TemplatePresentation::presentation($template->definition(), [
                'institution' => route('logos.institution', ['v' => $logos->version($logos->institutionPath())]),
                'faculty' => $logos->facultyUrl(data_get($academic, 'career.faculty_id')),
            ]),
            'sections' => $template->sections
                ->map(fn (TemplateSection $section): array => SyllabusSectionPayload::build($section, $stored, ['identification' => $identification]))
                ->values(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $values
     * @return array<string, mixed>
     */
    private function payload(string $subjectId, array $values, SubjectProfileFields $profile): array
    {
        $positions = $profile->positions();
        $present = fn (FieldDefinition $field): array => [
            'id' => $field->id,
            'key' => $field->clave,
            'label' => $field->etiqueta,
            'quien_llena' => $field->quien_llena,
            'content' => $field->contenido,
            ...(FieldResponse::enabled() ? ['template' => FieldResponse::template(FieldDocument::normalize($field->contenido))] : []),
            'section' => $field->block?->section?->titulo,
            'position' => $positions[$field->id] ?? 0,
        ];
        $responseFields = $profile->teacherFields();
        $values = [...array_diff_key($values, array_flip($responseFields->pluck('id')->all())), ...array_intersect_key($profile->setups($subjectId), array_flip($responseFields->pluck('id')->all()))];

        return [
            'fields' => $profile->fields()->map($present)->all(),
            'response_fields' => $responseFields->map($present)->all(),
            'values' => (object) $values,
            'complete' => $profile->completeBySubject([$subjectId])[$subjectId] ?? false,
            'definition' => TemplatePresentation::definition(TemplateContext::active()),
            'variables' => (object) array_column(TemplateVariables::catalog(), 'sample', 'key'),
        ];
    }
}
