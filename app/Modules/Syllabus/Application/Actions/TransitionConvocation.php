<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleInternalNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransitionConvocation
{
    public const PAUSE = 'pausar';

    public const RESUME = 'reanudar';

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleInternalNotification $notifications,
        private readonly GenerateConvocationSyllabi $generator,
    ) {}

    public function execute(
        Convocation $convocation,
        string $transition,
        User $actor,
        Request $request,
    ): Convocation {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Coordinator->value
            || $activeRole->carrera_id !== $convocation->carrera_id) {
            abort(403);
        }

        return DB::transaction(function () use ($activeRole, $actor, $convocation, $request, $transition): Convocation {
            $locked = Convocation::query()->lockForUpdate()->with('process')->findOrFail($convocation->id);

            [$from, $to] = match ($transition) {
                self::PAUSE => [[Convocation::STATE_OPEN], Convocation::STATE_PAUSED],
                self::RESUME => [[Convocation::STATE_PAUSED], Convocation::STATE_OPEN],
                default => throw ValidationException::withMessages(['transition' => 'La acción sobre la convocatoria no existe.']),
            };
            if (! in_array($locked->estado, $from, true)) {
                throw ValidationException::withMessages([
                    'convocation' => $transition === self::PAUSE
                        ? 'Solo una convocatoria abierta puede pausarse.'
                        : 'Solo una convocatoria pausada puede reanudarse.',
                ]);
            }
            if ($to === Convocation::STATE_OPEN && $locked->process->estado !== SyllabusProcess::STATE_OPEN) {
                throw ValidationException::withMessages([
                    'convocation' => 'El proceso institucional no está abierto; la convocatoria se reanudará cuando Administración lo reanude.',
                ]);
            }

            $generated = $to === Convocation::STATE_OPEN ? $this->generator->execute($locked) : 0;

            $locked->update(['estado' => $to]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: "convocatoria.{$to}",
                resourceType: 'convocatoria',
                resourceId: $locked->id,
                result: 'exito',
                metadata: $generated > 0 ? ['generated_count' => $generated] : [],
            );
            $this->notifyTeachers($locked, $to, $request);

            return $locked;
        });
    }

    private function notifyTeachers(Convocation $convocation, string $to, Request $request): void
    {
        $career = $convocation->career()->value('nombre');
        [$event, $title, $message] = $to === Convocation::STATE_PAUSED
            ? ['convocatoria.pausada', 'Convocatoria pausada', "La convocatoria de {$career} está en pausa. Su avance se conserva; podrá continuar cuando se reanude."]
            : ['convocatoria.reanudada', 'Convocatoria reanudada', "La convocatoria de {$career} se reanudó. Puede continuar con sus sílabos."];

        $this->notifications->execute(
            eventType: $event,
            deduplicationKey: "{$event}:{$convocation->id}:".Str::uuid(),
            recipientIds: array_keys($this->recipients->teacherSyllabusCounts([$convocation->id], exceptStates: ['aprobado'])),
            title: $title,
            message: $message,
            resourceType: 'convocatoria',
            resourceId: $convocation->id,
        );
    }
}
