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

/**
 * Pausa y reanudación por carrera. El cierre no es de la carrera: lo decide
 * Administración cerrando el proceso, que detiene a todas las convocatorias. La pausa es lo que permite a Coordinación corregir
 * su malla o sus fuentes sin que los docentes sigan trabajando sobre lo que cambia; no
 * toca a las demás carreras. Reanudar exige que el proceso institucional siga abierto.
 */
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
        ?string $reason,
        User $actor,
        Request $request,
    ): Convocation {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Coordinator->value
            || $activeRole->carrera_id !== $convocation->carrera_id) {
            abort(403);
        }

        return DB::transaction(function () use ($activeRole, $actor, $convocation, $reason, $request, $transition): Convocation {
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
            if ($to === Convocation::STATE_OPEN) {
                $locked->load('sources');
                if ($locked->sources->isEmpty()) {
                    throw ValidationException::withMessages([
                        'convocation' => 'La carrera necesita al menos una fuente académica activa antes de reanudar la convocatoria.',
                    ]);
                }
            }

            // Los paralelos creados durante la pausa nacen con su expediente al reanudar.
            $generated = $to === Convocation::STATE_OPEN ? $this->generator->execute($locked) : 0;

            $locked->update(['estado' => $to]);

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: "convocatoria.{$to}",
                resourceType: 'convocatoria',
                resourceId: $locked->id,
                result: 'exito',
                metadata: array_filter(
                    ['reason' => $reason, 'generated_count' => $generated ?: null],
                    fn (mixed $value): bool => $value !== null,
                ),
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );
            $this->notifyTeachers($locked, $to, $request);

            return $locked;
        });
    }

    /** Los sílabos aprobados no se detienen con la pausa: esos docentes no reciben aviso. */
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
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );
    }
}
