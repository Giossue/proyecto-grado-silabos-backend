<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CoordinatorAssignment;
use App\Modules\Identity\Application\Actions\AssignRole;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cambia quién coordina una carrera en un solo paso (I-39): cierra el nombramiento y el
 * rol de quien sale y abre los de quien entra (concediéndole el rol si no lo tiene). La
 * cuenta saliente no se toca: desactivarla es una decisión aparte en Usuarios. Sirve
 * también para nombrar a la primera persona cuando la carrera no tiene coordinación.
 *
 * @phpstan-type Result array{previous_user_id: string|null, role_removed: bool}
 */
class ReplaceCoordinator
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly AssignRole $assignRole,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    /**
     * @param  array{incoming_user_id: string}  $data
     * @return Result
     */
    public function execute(Career $career, array $data, User $actor, Request $request): array
    {
        $activeRole = $this->roles->resolve($request);
        $incoming = User::query()->where('activo', true)->find($data['incoming_user_id']);
        if ($incoming === null) {
            throw ValidationException::withMessages([
                'incoming_user_id' => 'La cuenta entrante no existe o está desactivada.',
            ]);
        }

        return DB::transaction(function () use ($actor, $activeRole, $career, $incoming, $request): array {
            $lockedCareer = Career::query()->whereKey($career->id)->lockForUpdate()->firstOrFail();
            $current = CoordinatorAssignment::query()
                ->effective()
                ->where('carrera_id', $lockedCareer->id)
                ->lockForUpdate()
                ->first();

            if ($current !== null && $current->usuario_id === $incoming->id) {
                throw ValidationException::withMessages([
                    'incoming_user_id' => 'Esa persona ya coordina la carrera.',
                ]);
            }

            $roleRemoved = false;
            if ($current !== null) {
                $current->update(['activo' => false]);
                $roleRemoved = true;
            }

            $this->assignRole->execute($incoming, [
                'role_code' => RoleCode::Coordinator->value,
                'career_id' => $lockedCareer->id,
            ], $actor, $request, notify: false);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'academico.coordinacion.reemplazada',
                resourceType: 'carrera',
                resourceId: $lockedCareer->id,
                result: 'exito',
                metadata: [
                    'previous_user_id' => $current?->usuario_id,
                    'incoming_user_id' => $incoming->id,
                    'role_removed' => $roleRemoved,
                ],
            );

            $this->notifyIncoming($lockedCareer, $incoming, $request);

            return ['previous_user_id' => $current?->usuario_id, 'role_removed' => $roleRemoved];
        });
    }

    private function notifyIncoming(Career $career, User $incoming, Request $request): void
    {
        $pending = Syllabus::query()->whereHas('convocation', fn ($convocation) => $convocation->where('carrera_id', $career->id))->where('estado', 'en_revision')->count();
        $waiting = match ($pending) {
            0 => '',
            1 => ' Hay 1 sílabo esperando revisión.',
            default => " Hay {$pending} sílabos esperando revisión.",
        };

        $this->notifications->execute(
            eventType: 'coordinacion.asignada',
            deduplicationKey: "coordinacion.asignada:{$career->id}:{$incoming->id}:".Str::uuid(),
            recipientIds: [$incoming->id],
            title: "Ahora coordina {$career->nombre}",
            message: "Se le asignó la coordinación de {$career->nombre}.{$waiting}",
            resourceType: 'carrera',
            resourceId: $career->id,
        );
    }
}
