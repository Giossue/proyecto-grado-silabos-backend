<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Application\CoordinatorConvocationReadiness;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenConvocation
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly TemplateStructureValidator $templateStructure,
        private readonly GenerateConvocationSyllabi $generator,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleInternalNotification $notifications,
        private readonly ConvocationSchedule $schedule,
        private readonly CoordinatorConvocationReadiness $readiness,
    ) {}

    public function execute(string $convocationId, User $actor, Request $request): Convocation
    {
        $activeRole = $this->roles->resolve($request);

        return DB::transaction(function () use ($actor, $convocationId, $activeRole, $request): Convocation {
            $convocation = Convocation::query()
                ->lockForUpdate()
                ->with(['career.faculty', 'process'])
                ->findOrFail($convocationId);
            if ($activeRole?->carrera_id !== $convocation->carrera_id || $activeRole->role->codigo_rol !== 'coordinador') {
                abort(403);
            }
            if ($convocation->estado !== 'preparacion') {
                throw ValidationException::withMessages(['convocation' => 'Solo una convocatoria en preparación puede abrirse.']);
            }
            if ($convocation->process->estado !== SyllabusProcess::STATE_OPEN) {
                throw ValidationException::withMessages(['convocation' => match ($convocation->process->estado) {
                    SyllabusProcess::STATE_PAUSED => 'El proceso institucional está en pausa. Podrá abrir cuando Administración lo reanude.',
                    SyllabusProcess::STATE_CLOSED => 'El proceso institucional ya se cerró. Prepare la convocatoria sobre un proceso vigente.',
                    default => 'El proceso institucional todavía no se abre. Podrá abrir cuando Administración lo inicie.',
                }]);
            }
            $this->readiness->assertReadyToOpen($convocation->carrera_id);
            $template = SyllabusTemplate::institutional();
            $this->templateStructure->assertUsable($template, 'convocation');

            $generated = $this->generator->execute($convocation);

            $convocation->update(['estado' => 'abierta']);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'convocatoria.abierta',
                resourceType: 'convocatoria',
                resourceId: $convocation->id,
                result: 'exito',
                metadata: ['generated_count' => $generated],
            );
            $this->notifyTeachers($convocation, $request);

            return $convocation;
        });
    }

    private function notifyTeachers(Convocation $convocation, Request $request): void
    {
        $deadline = $this->schedule->draftDeadline($convocation);
        $due = "; la entrega vence el {$this->notifications->date($deadline)}";

        foreach ($this->recipients->teacherSyllabusCounts([$convocation->id]) as $teacherId => $count) {
            $this->notifications->execute(
                eventType: 'convocatoria.abierta',
                deduplicationKey: "convocatoria.abierta:{$convocation->id}:{$teacherId}",
                recipientIds: [$teacherId],
                title: $count === 1 ? 'Tiene un sílabo por elaborar' : "Tiene {$count} sílabos por elaborar",
                message: "Se abrió la convocatoria de {$convocation->career->nombre}{$due}.",
                resourceType: 'convocatoria',
                resourceId: $convocation->id,
            );
        }
    }
}
