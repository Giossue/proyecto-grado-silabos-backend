<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\CatalogDeactivationBlocks;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Identity\Application\Actions\RevokeUserSessions;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\ActivationInvitation;
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
        private readonly CatalogDeactivationBlocks $blocks,
        private readonly RevokeUserSessions $sessions,
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

            $retired = 0;
            if (! $active) {
                $this->ensureMayDeactivate($entity, $recordId);
                if ($record instanceof Career) {
                    $retired = $this->retireUsers($record, $actor, $activeRole);
                }
            }

            $record->update(['activo' => $active]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: $entity === 'carrera'
                    ? 'academico.carrera.'.($active ? 'reactivacion' : 'desactivacion')
                    : "academico.{$entity}.cambio_estado",
                resourceType: $entity,
                resourceId: (string) $record->getKey(),
                result: 'exito',
                metadata: $entity === 'carrera' && ! $active ? ['active' => $active, 'retired_users' => $retired] : ['active' => $active],
            );

            return $record;
        }, $careerScope, null, null, $careerScope === [] ? null : $recordId);
    }

    /**
     * Al desactivar la carrera se retiran sus roles (coordinación y docencia) y los paralelos de sus
     * docentes. Las cuentas se conservan; quien queda sin ningún rol pierde sus sesiones y su enlace
     * de activación pendiente, como al quitar a un docente de la carrera.
     */
    private function retireUsers(Career $career, User $actor, RoleAssignment $activeRole): int
    {
        $assignments = RoleAssignment::query()->with('user')->where('carrera_id', $career->id)
            ->where('activo', true)->lockForUpdate()->get();
        foreach ($assignments as $assignment) {
            $parallels = TeacherAssignment::query()->where('asignacion_rol_id', $assignment->id)
                ->where('activo', true)->lockForUpdate()->get();
            foreach ($parallels as $parallel) {
                $parallel->delete();
            }
            $assignment->update(['activo' => false]);
            $user = $assignment->user;
            if ($user instanceof User) {
                // Su rol activo pudo ser este: vuelve a elegir al entrar.
                $this->sessions->execute($user);
                if (! RoleAssignment::query()->effective()->where('usuario_id', $user->id)->exists()) {
                    ActivationInvitation::query()->where('usuario_id', $user->id)->whereNull('usada_en')
                        ->whereNull('invalidada_en')->update(['invalidada_en' => now()]);
                }
            }
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'usuario.rol_retirado',
                resourceType: 'usuario',
                resourceId: $assignment->usuario_id,
                result: 'exito',
                metadata: [
                    'role' => $assignment->rol,
                    'career_id' => $career->id,
                    'removed_parallel_assignments' => $parallels->count(),
                    'reason' => 'carrera_desactivada',
                ],
            );
        }

        return $assignments->count();
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
        $reason = match ($entity) {
            'facultad' => $this->blocks->forFaculty($recordId),
            'carrera' => $this->blocks->forCareer($recordId),
            'asignatura' => Parallel::query()->where('asignatura_id', $recordId)->exists()
                ? 'Elimine primero los registros activos que dependen de este elemento.'
                : null,
            default => null,
        };

        if ($reason !== null) {
            throw ValidationException::withMessages(['record' => $reason]);
        }
    }
}
