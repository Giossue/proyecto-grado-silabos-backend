<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExtendProcessDeadline
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    public function execute(
        SyllabusProcess $process,
        string $newDueAt,
        string $reason,
        User $actor,
        Request $request,
    ): SyllabusProcess {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Administrator->value) {
            abort(403);
        }
        if ($process->estado === SyllabusProcess::STATE_CLOSED) {
            throw ValidationException::withMessages(['process' => 'Un proceso cerrado ya no admite prórrogas.']);
        }
        $stage = ConvocationSchedule::STAGE_DRAFT;
        $requested = CarbonImmutable::parse($newDueAt);
        $previous = $process->entrega_en;

        if ($requested->lessThanOrEqualTo($previous)) {
            throw ValidationException::withMessages([
                'due_at' => 'La nueva fecha debe ser posterior a la vigente, que es el '
                    .$previous->translatedFormat('d/m/Y H:i').'.',
            ]);
        }
        if ($requested->isPast()) {
            throw ValidationException::withMessages(['due_at' => 'La nueva fecha ya pasó.']);
        }

        return DB::transaction(function () use ($activeRole, $actor, $previous, $process, $reason, $request, $requested, $stage): SyllabusProcess {
            $locked = SyllabusProcess::query()->lockForUpdate()->findOrFail($process->id);
            $locked->update(['entrega_en' => $requested]);

            $reached = Convocation::query()->where('proceso_id', $locked->id)->count();

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'proceso_silabos.plazo_extendido',
                resourceType: 'proceso_silabos',
                resourceId: $locked->id,
                result: 'exito',
                metadata: [
                    'stage' => $stage,
                    'previous_due_at' => $previous->toIso8601String(),
                    'new_due_at' => $requested->toIso8601String(),
                    'reason' => $reason,
                    'convocations_reached' => $reached,
                ],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
            $this->notify($locked, $requested, $request);

            return $locked->refresh();
        });
    }

    private function notify(SyllabusProcess $process, CarbonImmutable $dueAt, Request $request): void
    {
        $this->notifications->execute(
            eventType: 'proceso.plazo_extendido',
            deduplicationKey: "proceso.plazo_extendido:{$process->id}:{$dueAt->getTimestamp()}",
            recipientIds: [
                ...$this->recipients->coordinatorsForProcess($process),
                ...$this->recipients->teachersWithPendingWork($process),
            ],
            title: 'Plazo extendido',
            message: "La entrega de sílabos del proceso «{$process->nombre}» se amplió hasta el {$this->notifications->date($dueAt)}.",
            resourceType: 'proceso_silabos',
            resourceId: $process->id,
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );
    }
}
