<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaveSubjectProfile
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly ProcessLocks $locks,
        private readonly RecordAuditEvent $audit,
        private readonly SubjectProfileFields $fields,
        private readonly InProgressWork $work,
    ) {}

    public function authorize(Subject $subject, Request $request): void
    {
        $role = $this->roles->resolve($request);
        abort_unless($request->user()?->activo === true && (
            AcademicStructurePermissions::isGovernanceContext($role)
            || (AcademicStructurePermissions::isCareerContext($role) && $role?->carrera_id === $subject->carrera_id)
        ), 403);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $editable
     * @return array<string, array<string, mixed>>
     */
    public function execute(Subject $subject, array $values, User $actor, Request $request, array $editable = []): array
    {
        $allowed = $this->fields->fields()->keyBy('id');
        $fieldIds = array_values(array_unique([...array_map('strval', array_keys($values)), ...array_map('strval', array_keys($editable))]));
        foreach ($fieldIds as $fieldId) {
            if (! $allowed->has($fieldId)) {
                throw ValidationException::withMessages(['values' => 'La ficha incluye un campo que ya no existe en la plantilla.']);
            }
        }

        return $this->work->replace($request, function () use ($actor, $allowed, $editable, $fieldIds, $request, $subject, $values): array {
            $lockedSubject = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $this->authorize($lockedSubject, $request);
            $this->locks->assertCareerEditable($lockedSubject->carrera_id);

            foreach ($fieldIds as $fieldId) {
                $template = $allowed->get($fieldId)->contenido;
                $current = FieldValue::query()
                    ->where('asignatura_id', $lockedSubject->id)
                    ->where('definicion_campo_id', $fieldId)
                    ->first();
                $teacher = array_key_exists($fieldId, $editable) ? $editable[$fieldId] === true : $current?->editable_docente === true;
                $document = match (true) {
                    array_key_exists($fieldId, $values) => is_array($values[$fieldId]) ? FieldResponse::normalize($template, $values[$fieldId], "values.{$fieldId}") : null,
                    is_array($current?->valor) => $current->valor,
                    default => null,
                };
                $empty = $document === null || FieldResponse::isEmpty($template, $document);
                if ($empty && ! $teacher) {
                    $current?->delete();

                    continue;
                }
                FieldValue::query()->updateOrCreate(
                    ['asignatura_id' => $lockedSubject->id, 'definicion_campo_id' => $fieldId],
                    ['valor' => $empty ? FieldDocument::empty() : $document, 'editable_docente' => $teacher],
                );
            }
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'academico.ficha_asignatura.guardada',
                resourceType: 'asignatura',
                resourceId: $lockedSubject->id,
                result: 'exito',
                metadata: ['campos' => count($fieldIds), 'editables_docente' => count(array_filter($editable, fn (mixed $value): bool => $value === true))],
            );

            return $this->fields->values($lockedSubject->id);
        }, $subject->carrera_id, null, 'Coordinación actualizó la ficha de la asignatura', $subject->id);
    }
}
