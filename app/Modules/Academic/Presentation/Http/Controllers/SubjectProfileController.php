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
        $academic = $context->forSubject($asignatura);
        $identification = IdentificationCard::build($academic, [], [], []);
        $template = SyllabusTemplate::institutional();
        $templates = $profile->templates($asignatura->id);
        $stored = fn (FieldDefinition $field): mixed => $templates[$field->id] ?? $field->contenido;

        return response()->json([
            'presentation' => TemplatePresentation::presentation($template->definition(), [
                'institution' => route('logos.institution', ['v' => $logos->version($logos->institutionPath())]),
                'faculty' => $logos->facultyUrl(data_get($academic, 'career.faculty_id')),
            ]),
            'sections' => $template->sections
                ->map(fn (TemplateSection $section): array => SyllabusSectionPayload::build($section, $stored, ['identification' => $identification], $templates))
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
        $fields = $profile->fields();

        return [
            'fields' => $fields->map($present)->all(),
            'values' => (object) array_intersect_key($values, array_flip($fields->pluck('id')->all())),
            'complete' => $profile->completeBySubject([$subjectId])[$subjectId] ?? false,
            'definition' => TemplatePresentation::definition(TemplateContext::active()),
            'variables' => (object) array_column(TemplateVariables::catalog(), 'sample', 'key'),
        ];
    }
}
