<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSyllabusProcess
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly TemplateStructureValidator $templateStructure,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    /** @param array{period_id: string, starts_at: string, due_at: string} $data */
    public function execute(array $data, User $actor, Request $request): SyllabusProcess
    {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Administrator->value) {
            abort(403);
        }

        $template = self::institutionalTemplate();
        $this->templateStructure->assertUsable($template, 'process');

        $careers = Career::query()->where('activo', true);
        if (! (clone $careers)->exists()) {
            throw ValidationException::withMessages(['process' => 'No existen carreras activas en la estructura institucional.']);
        }
        $withoutCoordinator = (clone $careers)->whereDoesntHave(
            'coordinatorAssignments',
            fn ($query) => $query->where('activo', true),
        )->count();
        if ($withoutCoordinator > 0) {
            throw ValidationException::withMessages(['process' => "La estructura institucional no está lista: {$withoutCoordinator} carrera(s) sin coordinación activa."]);
        }

        return DB::transaction(function () use ($actor, $activeRole, $data, $request, $template): SyllabusProcess {
            $active = SyllabusProcess::query()
                ->inProgress()
                ->with('academicPeriod:id,codigo')
                ->lockForUpdate()
                ->first(['id', 'periodo_academico_id']);
            if ($active !== null) {
                throw ValidationException::withMessages([
                    'process' => "La convocatoria «{$active->nombre}» sigue vigente. Ciérrela antes de abrir otra convocatoria institucional.",
                ]);
            }
            if (SyllabusProcess::query()->where('periodo_academico_id', $data['period_id'])->exists()) {
                throw ValidationException::withMessages([
                    'period_id' => 'Ya existe una convocatoria institucional para este período académico.',
                ]);
            }
            $process = SyllabusProcess::query()->create([
                'periodo_academico_id' => $data['period_id'],
                'inicia_en' => $data['starts_at'],
                'entrega_en' => $data['due_at'],
                'estado' => SyllabusProcess::STATE_OPEN,
            ]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'proceso_silabos.creado',
                resourceType: 'proceso_silabos',
                resourceId: $process->id,
                result: 'exito',
                metadata: [
                    'template_id' => $template->id,
                    'period_id' => $process->periodo_academico_id,
                    'starts_at' => $process->inicia_en->toIso8601String(),
                    'due_at' => $process->entrega_en->toIso8601String(),
                ],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'proceso_silabos.abierto',
                resourceType: 'proceso_silabos',
                resourceId: $process->id,
                result: 'exito',
                metadata: ['previous_state' => null],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
            $this->notifications->execute(
                eventType: 'proceso.abierto',
                deduplicationKey: "proceso.abierto:{$process->id}",
                recipientIds: $this->recipients->coordinatorsForProcess($process),
                title: 'Convocatoria institucional abierta',
                message: "Se abrió la convocatoria institucional «{$process->nombre}». Ya puede iniciar la de su carrera; la entrega vence el {$this->notifications->date($process->entrega_en)}.",
                resourceType: 'proceso_silabos',
                resourceId: $process->id,
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $process;
        });
    }

    public static function institutionalTemplate(): SyllabusTemplate
    {
        $template = SyllabusTemplate::query()->first();

        if ($template === null || ! $template->hasStructure()) {
            throw ValidationException::withMessages([
                'process' => 'La plantilla institucional está vacía. Aplique el formato oficial o configure sus bloques antes de abrir la convocatoria.',
            ]);
        }

        return $template;
    }
}
