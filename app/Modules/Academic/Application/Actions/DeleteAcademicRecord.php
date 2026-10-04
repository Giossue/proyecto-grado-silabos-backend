<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteAcademicRecord
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly AcademicPeriodPlanning $periodPlanning,
    ) {}

    public function execute(string $entity, string $recordId, User $actor, Request $request): void
    {
        $role = $this->roles->resolve($request);
        if (! $role instanceof RoleAssignment) {
            throw new AuthorizationException('Seleccione un rol vigente.');
        }

        if (AcademicStructurePermissions::isGovernanceContext($role)) {
            if (! in_array($entity, ['facultad', 'carrera', 'periodo'], true)) {
                throw new AuthorizationException('Este registro no pertenece a la estructura institucional.');
            }
        } elseif (AcademicStructurePermissions::isCareerContext($role) && $role->carrera_id !== null) {
            if ($entity !== 'asignacion_docente') {
                throw new AuthorizationException('Este registro no admite eliminación desde Coordinación.');
            }
        } else {
            throw new AuthorizationException('No puede eliminar este registro con el rol activo.');
        }

        DB::transaction(function () use ($actor, $entity, $recordId, $role): void {
            $record = AcademicStructurePermissions::isGovernanceContext($role)
                ? match ($entity) {
                    'facultad' => Faculty::query()->lockForUpdate()->findOrFail($recordId),
                    'carrera' => Career::query()->lockForUpdate()->findOrFail($recordId),
                    'periodo' => AcademicPeriod::query()->lockForUpdate()->findOrFail($recordId),
                    default => throw new \LogicException('Entidad institucional no admitida.'),
                }
            : match ($entity) {
                'asignacion_docente' => TeacherAssignment::query()
                    ->whereHas('parallel.scheduledSubject.subject', fn ($query) => $query->where('carrera_id', $role->carrera_id))
                    ->lockForUpdate()->findOrFail($recordId),
                default => throw new \LogicException('Entidad de carrera no admitida.'),
            };

            if ($record instanceof TeacherAssignment) {
                $this->periodPlanning->assertTeacherAssignmentMayChange($record);
            }

            $this->assertDeletable($entity, $record);
            $metadata = $this->delete($entity, $record);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $role->id,
                action: "academico.{$entity}.eliminacion",
                resourceType: $entity,
                resourceId: $recordId,
                result: 'exito',
                metadata: $metadata,
            );
        });
    }

    private function assertDeletable(string $entity, Model $record): void
    {
        $hasDependencies = match ($entity) {
            'facultad' => Career::query()->where('facultad_id', $record->getKey())->exists(),
            'carrera' => Subject::query()->where('carrera_id', $record->getKey())->exists()
                || DB::table('documentos_importacion')->where('carrera_id', $record->getKey())->exists()
                || DB::table('convocatorias_carreras')->where('carrera_id', $record->getKey())->exists()
                || DB::table('asignaciones_rol')->where('carrera_id', $record->getKey())->exists(),
            // I-172: un periodo sin convocatoria se elimina con su programación; con convocatoria, se elimina ella primero.
            'periodo' => DB::table('convocatorias_universidad')->where('periodo_academico_id', $record->getKey())->exists(),
            'asignacion_docente' => false,
            default => true,
        };

        if ($hasDependencies) {
            throw ValidationException::withMessages([
                'record' => 'No se puede eliminar porque ya tiene registros dependientes o historia académica. Revise las consecuencias y conserve ese historial.',
            ]);
        }
    }

    /** @return array<string, int|string> */
    private function delete(string $entity, Model $record): array
    {
        $name = (string) ($record->getAttribute('nombre') ?? $record->getAttribute('codigo') ?? $record->getKey());
        $metadata = ['name' => $name];
        if ($entity === 'periodo') {
            $metadata += $this->deletePeriodPlanning((string) $record->getKey());
        }
        $record->delete();

        return $metadata;
    }

    /**
     * Borra materias programadas, paralelos y asignaciones docentes del periodo. Sin convocatoria no hay sílabos.
     *
     * @return array<string, int>
     */
    private function deletePeriodPlanning(string $periodId): array
    {
        $scheduledIds = ScheduledSubject::query()->where('periodo_academico_id', $periodId)->lockForUpdate()->pluck('id');
        $parallelIds = Parallel::query()->whereIn('programacion_asignatura_id', $scheduledIds)->lockForUpdate()->pluck('id');
        $assignments = TeacherAssignment::query()->whereIn('paralelo_id', $parallelIds)->delete();
        $parallels = Parallel::query()->whereIn('id', $parallelIds)->delete();
        $scheduled = ScheduledSubject::query()->whereIn('id', $scheduledIds)->delete();

        return ['deleted_scheduled_subjects' => $scheduled, 'deleted_parallels' => $parallels, 'deleted_teacher_assignments' => $assignments];
    }
}
