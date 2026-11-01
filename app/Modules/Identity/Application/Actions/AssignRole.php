<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\CoordinationHandover;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignRole
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly CoordinationHandover $handover,
    ) {}

    /** @param array{role_code: string, career_id?: string|null, replace_coordinator?: bool} $data */
    public function execute(User $target, array $data, User $actor, Request $request, bool $notify = true): RoleAssignment
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($actor, $activeRole, $data, $target): RoleAssignment {
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

            $careerId = $role->hasCareerScope() ? ($data['career_id'] ?? null) : null;
            if ($data['role_code'] === RoleCode::Coordinator->value) {
                $current = RoleAssignment::query()
                    ->effective()
                    ->where('carrera_id', $careerId)
                    ->where('usuario_id', '!=', $target->id)
                    ->whereHas('user', fn ($query) => $query->where('activo', true))
                    ->where('rol', RoleCode::Coordinator->value)
                    ->with('user:id,nombre')
                    ->lockForUpdate()
                    ->first();
                if ($current !== null) {
                    // Una carrera tiene un solo coordinador: asignar otro es reemplazarlo, y se confirma antes.
                    if (($data['replace_coordinator'] ?? false) !== true) {
                        throw ValidationException::withMessages([
                            'replace_coordinator' => "La carrera ya tiene como coordinador a {$current->user->nombre}.",
                        ]);
                    }
                    $current->update(['activo' => false]);
                    $returned = $this->handover->returnApprovals((string) $careerId);
                    $this->audit->execute(
                        actorId: $actor->id,
                        roleAssignmentId: $activeRole?->id,
                        action: 'academico.coordinacion.reemplazada',
                        resourceType: 'carrera',
                        resourceId: (string) $careerId,
                        result: 'exito',
                        metadata: ['previous_user_id' => $current->usuario_id, 'incoming_user_id' => $target->id, 'returned_to_review' => count($returned)],
                    );
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
            );

            return $assignment;
        });
    }
}
