<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AssignRole
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    /** @param array{role_code: string, career_id?: string|null} $data */
    public function execute(User $target, array $data, User $actor, Request $request, bool $notify = true): RoleAssignment
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($actor, $activeRole, $data, $notify, $request, $target): RoleAssignment {
            $role = RoleCode::tryFrom($data['role_code']);
            if ($role === null) {
                throw ValidationException::withMessages(['role_code' => 'El rol indicado no es válido.']);
            }

            $activeRoles = RoleAssignment::query()
                ->where('usuario_id', $target->id)
                ->effective()
                ->lockForUpdate()
                ->pluck('rol');
            $hasAdministrator = $activeRoles->contains(RoleCode::Administrator->value);
            $hasScopedRole = $activeRoles->contains(
                fn (mixed $assignedRole): bool => $assignedRole !== RoleCode::Administrator->value,
            );

            if (($role === RoleCode::Administrator && $hasScopedRole)
                || ($role !== RoleCode::Administrator && $hasAdministrator)) {
                throw ValidationException::withMessages([
                    'role_code' => $role === RoleCode::Administrator
                        ? 'El rol Administrador es exclusivo y no puede agregarse mientras la cuenta conserve otro rol activo.'
                        : 'Una cuenta con el rol Administrador solo puede conservar ese rol.',
                ]);
            }

            $careerId = $data['role_code'] === RoleCode::Administrator->value
                ? null
                : ($data['career_id'] ?? null);
            if ($data['role_code'] === RoleCode::Coordinator->value) {
                $alreadyCoordinated = RoleAssignment::query()
                    ->effective()
                    ->where('carrera_id', $careerId)
                    ->where('usuario_id', '!=', $target->id)
                    ->whereHas('user', fn ($query) => $query->where('activo', true))
                    ->where('rol', RoleCode::Coordinator->value)
                    ->exists();
                if ($alreadyCoordinated) {
                    throw ValidationException::withMessages([
                        'role_code' => 'La carrera ya tiene una coordinación activa. Use el reemplazo de coordinación.',
                    ]);
                }
            }
            $assignment = RoleAssignment::query()->firstOrCreate(
                [
                    'usuario_id' => $target->id,
                    'rol' => $role->value,
                    'carrera_id' => $careerId,
                ],
                [
                    'activo' => true,
                ],
            );

            $granted = $assignment->wasRecentlyCreated || ! $assignment->activo;
            if (! $assignment->activo) {
                $assignment->update(['activo' => true]);
            }

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'usuario.rol_asignado',
                resourceType: 'usuario',
                resourceId: $target->id,
                result: 'exito',
                metadata: [
                    'role' => $data['role_code'],
                    'career_id' => $careerId,
                ],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
            if ($notify && $granted && $target->activo && $target->hasVerifiedEmail()) {
                $this->notifyGrantedRole($target, $assignment, $request);
            }

            return $assignment;
        });
    }

    private function notifyGrantedRole(User $target, RoleAssignment $assignment, Request $request): void
    {
        $career = $assignment->career()->value('nombre');
        $scope = is_string($career) ? " en {$career}" : '';

        $this->notifications->execute(
            eventType: 'usuario.rol_asignado',
            deduplicationKey: "usuario.rol_asignado:{$assignment->id}:".Str::uuid(),
            recipientIds: [$target->id],
            title: 'Nuevo rol disponible',
            message: "Ahora también puede ingresar como {$assignment->role->nombre_rol}{$scope}. Cámbielo desde el selector de ámbito.",
            resourceType: 'asignacion_rol',
            resourceId: $assignment->id,
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );
    }
}
