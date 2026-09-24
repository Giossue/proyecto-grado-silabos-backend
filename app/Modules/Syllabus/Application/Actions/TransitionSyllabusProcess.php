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
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo del proceso institucional: abierto ⇄ pausado → cerrado.
 *
 * Pausar detiene a toda la universidad —envíos y ediciones docentes— y es lo que
 * habilita cambiar la plantilla; por eso pide motivo. Reanudar vuelve a comprobar la
 * plantilla: pudo cambiarse durante la pausa. Cerrar es definitivo.
 */
class TransitionSyllabusProcess
{
    public const PAUSE = 'pausar';

    public const RESUME = 'reanudar';

    public const CLOSE = 'cerrar';

    /** @var array<string, array{from: list<string>, to: string}> */
    private const TRANSITIONS = [
        self::PAUSE => ['from' => [SyllabusProcess::STATE_OPEN], 'to' => SyllabusProcess::STATE_PAUSED],
        self::RESUME => ['from' => [SyllabusProcess::STATE_PAUSED], 'to' => SyllabusProcess::STATE_OPEN],
        self::CLOSE => ['from' => [SyllabusProcess::STATE_OPEN, SyllabusProcess::STATE_PAUSED], 'to' => SyllabusProcess::STATE_CLOSED],
    ];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly TemplateStructureValidator $templateStructure,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleInternalNotification $notifications,
    ) {}

    public function execute(
        SyllabusProcess $process,
        string $transition,
        ?string $reason,
        User $actor,
        Request $request,
    ): SyllabusProcess {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Administrator->value) {
            abort(403);
        }
        $rule = self::TRANSITIONS[$transition] ?? null;
        if ($rule === null) {
            throw ValidationException::withMessages(['transition' => 'La acción sobre el proceso no existe.']);
        }

        return DB::transaction(function () use ($activeRole, $actor, $process, $reason, $request, $rule): SyllabusProcess {
            $locked = SyllabusProcess::query()->lockForUpdate()->findOrFail($process->id);
            if (! in_array($locked->estado, $rule['from'], true)) {
                throw ValidationException::withMessages([
                    'process' => 'El proceso no está en un estado que admita esta acción.',
                ]);
            }

            if ($rule['to'] === SyllabusProcess::STATE_OPEN) {
                // Lo que antes garantizaba «publicar»: la plantilla se usa completa o no se usa.
                $this->templateStructure->assertUsable(SyllabusTemplate::institutional(), 'process');
            }

            $previous = $locked->estado;
            $locked->update([
                'estado' => $rule['to'],
            ]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: "proceso_silabos.{$rule['to']}",
                resourceType: 'proceso_silabos',
                resourceId: $locked->id,
                result: 'exito',
                metadata: array_filter([
                    'previous_state' => $previous,
                    'reason' => $reason,
                ], fn (mixed $value): bool => $value !== null),
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
            $this->notify($locked, $rule['to'], $request);

            return $locked;
        });
    }

    private function notify(SyllabusProcess $process, string $to, Request $request): void
    {
        [$event, $title, $message] = match ($to) {
            SyllabusProcess::STATE_PAUSED => ['proceso.pausado', 'Convocatoria institucional pausada', "La convocatoria institucional «{$process->nombre}» está en pausa. Su avance se conserva; podrá continuar cuando Administración la reanude."],
            SyllabusProcess::STATE_OPEN => ['proceso.reanudado', 'Convocatoria institucional reanudada', "La convocatoria institucional «{$process->nombre}» se reanudó. Puede continuar con sus sílabos."],
            default => ['proceso.cerrado', 'Convocatoria institucional cerrada', "La convocatoria institucional «{$process->nombre}» se cerró. Ya no se reciben envíos."],
        };

        $this->notifications->execute(
            eventType: $event,
            deduplicationKey: "{$event}:{$process->id}:".Str::uuid(),
            recipientIds: [
                ...$this->recipients->coordinatorsForProcess($process),
                ...$this->recipients->teachersWithPendingWork($process),
            ],
            title: $title,
            message: $message,
            resourceType: 'proceso_silabos',
            resourceId: $process->id,
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );
    }
}
