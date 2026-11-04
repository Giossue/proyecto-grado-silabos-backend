<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\ConvocationSchedule;
use App\Modules\Syllabus\Application\DeadlineExtensions;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Application\ProcessClosing;
use App\Modules\Syllabus\Application\ProcessTemplateChange;
use App\Modules\Syllabus\Application\ReopenCatchUp;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Support\InstitutionDate;
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
        private readonly ProcessClosing $closing,
        private readonly ProcessTemplateChange $templateChange,
        private readonly RegenerateProcessSyllabi $regenerate,
        private readonly TemplateStructureValidator $templateStructure,
        private readonly GenerateConvocationSyllabi $generator,
        private readonly ReopenCatchUp $catchUp,
    ) {}

    /** @return array{process: SyllabusProcess, confirmation_required: bool, confirmation_code: string|null, counts: array<string, int>, pending_counts: array<string, int>|null, reopened: bool} */
    public function execute(
        SyllabusProcess $process,
        string $newDueAt,
        User $actor,
        Request $request,
    ): array {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Administrator->value) {
            abort(403);
        }
        $stage = ConvocationSchedule::STAGE_DRAFT;
        $requested = CarbonImmutable::parse($newDueAt);
        $requestedOn = InstitutionDate::dateOf($requested);

        return DB::transaction(function () use ($activeRole, $actor, $process, $request, $requested, $requestedOn, $stage): array {
            $locked = SyllabusProcess::query()->lockForUpdate()->findOrFail($process->id);
            $reopening = $locked->estado === SyllabusProcess::STATE_CLOSED;
            if ($reopening) {
                SyllabusProcess::query()->inProgress()->whereKeyNot($locked->id)->lockForUpdate()->get(['id']);
            }
            $previous = $locked->entrega_en;
            $previousOn = InstitutionDate::dateOf($previous);

            $blocked = DeadlineExtensions::blockReason($locked);
            if ($blocked !== null) {
                throw ValidationException::withMessages(['process' => $blocked]);
            }
            if ($requestedOn < InstitutionDate::todayOn()) {
                throw ValidationException::withMessages(['due_at' => 'La nueva fecha ya pasó.']);
            }
            if ($requestedOn <= $previousOn) {
                throw ValidationException::withMessages([
                    'due_at' => 'La nueva fecha debe ser posterior a la vigente, que es el '
                        .CarbonImmutable::parse($previousOn, 'UTC')->translatedFormat('d/m/Y').'.',
                ]);
            }
            if ($requestedOn > DeadlineExtensions::limitOn($locked)) {
                throw ValidationException::withMessages(['due_at' => DeadlineExtensions::pastLimitMessage($locked)]);
            }

            $changedAt = null;
            $counts = [];
            $reopened = [];
            $convocationIds = [];
            $plan = null;
            if ($reopening) {
                $this->templateStructure->assertUsable(SyllabusTemplate::institutional(), 'process');
                $changedAt = ProcessTemplateChange::since(ProcessClosing::templateReference($locked));
                $convocationIds = $this->catchUp->convocationsToReopen($locked);
                $plan = $this->catchUp->plan($convocationIds, $locked->finalizada_en);
                $pending = array_sum($plan['counts']) > 0 ? $plan['counts'] : null;
                if ($changedAt !== null) {
                    $counts = $this->regenerate->countsToReopen($locked);
                    if (! $request->boolean('confirmar_rehacer')) {
                        return $this->needsConfirmation($locked, 'plantilla_cambiada', $counts, $pending);
                    }
                }
                if ($pending !== null && ! $request->boolean('confirmar_cambios')) {
                    return $this->needsConfirmation($locked, 'cambios_pendientes', $pending, null);
                }
                $reopened = $this->closing->reopen($locked);
            }

            $number = $locked->cantidad_prorrogas + 1;
            $locked->update(['entrega_en' => $requested, 'cantidad_prorrogas' => $number]);
            if ($changedAt !== null) {
                $this->templateChange->adapt($locked, $changedAt, $counts, $actor->id, $activeRole->id);
            }
            if ($plan !== null) {
                $this->catchUp->apply($plan, $convocationIds, $locked->periodo_academico_id);
            }
            foreach (Convocation::query()->whereKey($reopened)->with('process')->orderBy('id')->lockForUpdate()->get() as $convocation) {
                $this->generator->execute($convocation, allowEmpty: true, skipIncomplete: true);
            }

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
                    'convocations_reached' => $reached,
                    'extension_number' => $number,
                ],
            );
            if ($reopening) {
                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $activeRole->id,
                    action: 'proceso_silabos.reabierto',
                    resourceType: 'proceso_silabos',
                    resourceId: $locked->id,
                    result: 'exito',
                    metadata: ['previous_state' => SyllabusProcess::STATE_CLOSED],
                );
            }
            $this->notify($locked, $requested, $request);

            return ['process' => $locked->refresh(), 'confirmation_required' => false, 'confirmation_code' => null, 'counts' => $counts, 'pending_counts' => null, 'reopened' => $reopening];
        });
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>|null  $pendingCounts
     * @return array{process: SyllabusProcess, confirmation_required: bool, confirmation_code: string|null, counts: array<string, int>, pending_counts: array<string, int>|null, reopened: bool}
     */
    private function needsConfirmation(SyllabusProcess $process, string $code, array $counts, ?array $pendingCounts): array
    {
        return ['process' => $process, 'confirmation_required' => true, 'confirmation_code' => $code, 'counts' => $counts, 'pending_counts' => $pendingCounts, 'reopened' => false];
    }

    private function notify(SyllabusProcess $process, CarbonImmutable $dueAt, Request $request): void
    {
        $this->notifications->execute(
            eventType: 'proceso.plazo_extendido',
            deduplicationKey: "proceso.plazo_extendido:{$process->id}:{$dueAt->getTimestamp()}",
            recipientIds: $this->recipients->teachersWithPendingWork($process),
            title: 'Plazo extendido',
            message: "La entrega de sílabos del proceso «{$process->nombre}» se amplió hasta el {$this->notifications->date($dueAt)}.",
            resourceType: 'proceso_silabos',
            resourceId: $process->id,
        );
    }
}
