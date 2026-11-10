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
     * @return array<string, array<string, mixed>>
     */
    public function execute(Subject $subject, array $values, User $actor, Request $request): array
    {
        $allowed = $this->fields->fields()->keyBy('id');
        $teacher = $this->fields->teacherFields()->keyBy('id');
        $fieldIds = array_map('strval', array_keys($values));
        foreach ($fieldIds as $fieldId) {
            if (! $allowed->has($fieldId) && ! $teacher->has($fieldId)) {
                throw ValidationException::withMessages(['values' => 'El proyecto de carrera incluye un campo que ya no existe en la plantilla.']);
            }
        }

        return $this->work->keepContent($request, $subject->carrera_id, function () use ($actor, $allowed, $fieldIds, $request, $subject, $teacher, $values): array {
            $lockedSubject = Subject::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $this->authorize($lockedSubject, $request);
            $this->locks->assertCareerEditable($lockedSubject->carrera_id);

            foreach ($fieldIds as $fieldId) {
                $current = FieldValue::query()
                    ->where('asignatura_id', $lockedSubject->id)
                    ->where('definicion_campo_id', $fieldId)
                    ->first();
                if ($teacher->has($fieldId)) {
                    $definition = $teacher->get($fieldId)->contenido;
                    $setup = is_array($values[$fieldId]) ? FieldResponse::setup($definition, $values[$fieldId], "values.{$fieldId}") : null;
                    if ($setup === null || $setup == FieldResponse::template(FieldDocument::normalize($definition))) {
                        $current?->delete();

                        continue;
                    }
                    FieldValue::query()->updateOrCreate(
                        ['asignatura_id' => $lockedSubject->id, 'definicion_campo_id' => $fieldId],
                        ['valor' => $setup],
                    );

                    continue;
                }
                $template = $allowed->get($fieldId)->contenido;
                $document = match (true) {
                    array_key_exists($fieldId, $values) => is_array($values[$fieldId]) ? FieldResponse::normalize($template, $values[$fieldId], "values.{$fieldId}") : null,
                    is_array($current?->valor) => $current->valor,
                    default => null,
                };
                $empty = $document === null || FieldResponse::isEmpty($template, $document);
                if ($empty) {
                    $current?->delete();

                    continue;
                }
                FieldValue::query()->updateOrCreate(
                    ['asignatura_id' => $lockedSubject->id, 'definicion_campo_id' => $fieldId],
                    ['valor' => $document],
                );
            }
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $this->roles->resolve($request)?->id,
                action: 'academico.ficha_asignatura.guardada',
                resourceType: 'asignatura',
                resourceId: $lockedSubject->id,
                result: 'exito',
                metadata: ['campos' => count($fieldIds)],
            );

            return $this->fields->values($lockedSubject->id);
        }, [$subject->id]);
    }
}
