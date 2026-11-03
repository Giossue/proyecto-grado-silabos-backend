<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteSyllabusProcess
{
    public const EVENT = 'proceso.eliminado';

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly InProgressWork $work,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    public function execute(SyllabusProcess $process, User $actor, Request $request, string $message): void
    {
        $activeRole = $this->roles->resolve($request);
        abort_unless($activeRole?->role->codigo_rol === RoleCode::Administrator->value, 403);

        DB::transaction(function () use ($activeRole, $actor, $message, $process): void {
            $locked = SyllabusProcess::query()->with('academicPeriod')->lockForUpdate()->findOrFail($process->id);
            if ($locked->estado === SyllabusProcess::STATE_CLOSED) {
                throw ValidationException::withMessages([
                    'process' => 'Una convocatoria cerrada no se elimina: queda como historial.',
                ]);
            }

            $convocations = Convocation::query()
                ->where('proceso_id', $locked->id)
                ->lockForUpdate()
                ->get();
            $syllabusIds = array_values(array_map('strval', Syllabus::query()
                ->whereIn('convocatoria_id', $convocations->modelKeys())
                ->lockForUpdate()
                ->pluck('id')
                ->all()));
            $recipientIds = array_values(array_diff(array_unique([
                ...$this->recipients->teachersForProcess($locked),
                ...$this->recipients->coordinatorsForProcess($locked),
                ...$this->recipients->librarians(),
            ]), [$actor->id]));
            $state = $locked->estado;
            $name = $locked->nombre;

            $this->work->purge($syllabusIds);
            DB::statement("SET LOCAL app.reinicio_institucional = 'off'");
            Convocation::query()->whereKey($convocations->modelKeys())->delete();
            $locked->delete();

            $this->notifications->execute(
                eventType: self::EVENT,
                deduplicationKey: self::EVENT.":{$locked->id}",
                recipientIds: $recipientIds,
                title: 'Convocatoria eliminada',
                message: "Administración eliminó la convocatoria {$name} y todos sus sílabos. Mensaje: {$message}",
                resourceType: 'periodo_academico',
                resourceId: $locked->periodo_academico_id,
                periodId: $locked->periodo_academico_id,
            );

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'proceso_silabos.eliminado',
                resourceType: 'proceso_silabos',
                resourceId: $process->id,
                result: 'exito',
                metadata: [
                    'period_id' => $locked->periodo_academico_id,
                    'previous_state' => $state,
                    'deleted_convocations' => $convocations->count(),
                    'deleted_syllabi' => count($syllabusIds),
                    'notified_people' => count($recipientIds),
                ],
            );
        });
    }
}
