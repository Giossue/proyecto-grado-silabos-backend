<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
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
    ) {}

    public function execute(string $entity, string $recordId, User $actor, Request $request): void
    {
        $role = $this->roles->resolve($request);
        if (! $role instanceof RoleAssignment) {
            throw new AuthorizationException('Seleccione un rol vigente.');
        }

        if (AcademicStructurePermissions::isGovernanceContext($role)) {
            // Facultades y carreras no se eliminan: se desactivan y conservan su historial.
            if ($entity !== 'periodo') {
                throw new AuthorizationException('Este registro no se elimina: desactívelo.');
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
                    'periodo' => AcademicPeriod::query()->lockForUpdate()->findOrFail($recordId),
                    default => throw new \LogicException('Entidad institucional no admitida.'),
                }
            : match ($entity) {
                'asignacion_docente' => TeacherAssignment::query()
                    ->whereHas('parallel.subject', fn ($query) => $query->where('carrera_id', $role->carrera_id))
                    ->lockForUpdate()->findOrFail($recordId),
                default => throw new \LogicException('Entidad de carrera no admitida.'),
            };

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
            'periodo' => DB::table('convocatorias_universidad')->where('periodo_academico_id', $record->getKey())->exists(),
            'asignacion_docente' => false,
            default => true,
        };

        if (! $hasDependencies) {
            return;
        }

        throw ValidationException::withMessages([
            'record' => match ($entity) {
                'periodo' => 'Este período ya tiene una convocatoria con su historial, por eso no se puede eliminar.',
                default => 'No se puede eliminar porque ya tiene registros dependientes o historia académica. Revise las consecuencias y conserve ese historial.',
            },
        ]);
    }

    /** @return array<string, int|string> */
    private function delete(string $entity, Model $record): array
    {
        $name = (string) ($record->getAttribute('nombre') ?? $record->getAttribute('codigo') ?? $record->getKey());
        $record->delete();

        return ['name' => $name];
    }
}
