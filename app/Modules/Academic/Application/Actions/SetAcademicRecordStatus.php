<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SetAcademicRecordStatus
{
    /** @var array<string, class-string<Model>> */
    private const MODELS = [
        'facultad' => Faculty::class,
        'carrera' => Career::class,
        'asignatura' => Subject::class,
        'asignacion_docente' => TeacherAssignment::class,
    ];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
    ) {}

    public function execute(
        string $entity,
        string $recordId,
        bool $active,
        User $actor,
        Request $request,
    ): Model {
        $modelClass = self::MODELS[$entity] ?? null;

        if ($modelClass === null) {
            throw ValidationException::withMessages(['entity' => 'El tipo de registro no admite este cambio.']);
        }

        $activeRole = $this->roles->resolve($request);

        if (! $activeRole instanceof RoleAssignment || ! AcademicStructurePermissions::mayChangeStatus($activeRole, $entity)) {
            throw new AuthorizationException('No puede cambiar este registro con el rol activo.');
        }
        if (AcademicStructurePermissions::isCareerContext($activeRole) && $entity !== 'asignacion_docente') {
            $this->locks->assertCareerEditable($activeRole->carrera_id);
        }
        $careerScope = $entity === 'asignatura' && AcademicStructurePermissions::isCareerContext($activeRole)
            ? $activeRole->carrera_id
            : [];

        return $this->work->replace($request, function () use ($active, $actor, $activeRole, $entity, $modelClass, $recordId): Model {
            $record = AcademicStructurePermissions::isCareerContext($activeRole)
                ? $this->findCareerRecord($entity, $recordId, $activeRole->carrera_id)
                : $modelClass::query()->lockForUpdate()->findOrFail($recordId);

            if ($record instanceof TeacherAssignment) {
                if ($record->activo !== $active && $active) {
                    throw ValidationException::withMessages([
                        'record' => 'Una asignación cerrada no se reactiva. Asigne de nuevo al docente en el paralelo.',
                    ]);
                }
            }

            if (! $active) {
                $this->ensureMayDeactivate($entity, $recordId);
            }

            $record->update(['activo' => $active]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: "academico.{$entity}.cambio_estado",
                resourceType: $entity,
                resourceId: (string) $record->getKey(),
                result: 'exito',
                metadata: ['active' => $active],
            );

            return $record;
        }, $careerScope);
    }

    private function findCareerRecord(string $entity, string $recordId, ?string $careerId): Model
    {
        if ($careerId === null) {
            throw new AuthorizationException('Seleccione una coordinación activa con carrera.');
        }

        return match ($entity) {
            'asignatura' => Subject::query()->whereHas(
                'career',
                fn ($query) => $query->whereKey($careerId),
            )->lockForUpdate()->findOrFail($recordId),
            'asignacion_docente' => TeacherAssignment::query()->whereHas(
                'parallel.subject',
                fn ($query) => $query->where('carrera_id', $careerId),
            )->lockForUpdate()->findOrFail($recordId),
            default => throw new AuthorizationException('El registro no pertenece a la gestión de carrera.'),
        };
    }

    private function ensureMayDeactivate(string $entity, string $recordId): void
    {
        $hasActiveDependants = match ($entity) {
            'facultad' => Career::query()->where('facultad_id', $recordId)->where('activo', true)->exists(),
            'carrera' => Subject::query()->where('carrera_id', $recordId)->where('activo', true)->exists(),
            'asignatura' => Parallel::query()->where('asignatura_id', $recordId)->exists(),
            default => false,
        };

        if ($hasActiveDependants) {
            throw ValidationException::withMessages([
                'record' => 'Elimine primero los registros activos que dependen de este elemento.',
            ]);
        }
    }
}
