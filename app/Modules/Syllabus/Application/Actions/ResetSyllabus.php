<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\AcademicContextSnapshot;
use App\Modules\Syllabus\Application\InheritMasterValues;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResetSyllabus
{
    /** @var list<string> */
    public const RESETTABLE_STATES = ['borrador', 'en_revision', 'correccion_solicitada'];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly ScheduleWorkflowNotification $notifications,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly AcademicContextSnapshot $academicContext,
        private readonly InheritMasterValues $inherit,
    ) {}

    public function execute(Syllabus $syllabus, User $actor, Request $request, string $reason): Syllabus
    {
        $activeRole = $this->roles->resolve($request);
        if ($activeRole?->role->codigo_rol !== RoleCode::Coordinator->value
            || $activeRole->carrera_id !== $syllabus->convocation()->value('carrera_id')) {
            abort(403);
        }

        return DB::transaction(function () use ($activeRole, $actor, $syllabus, $reason): Syllabus {
            $locked = Syllabus::query()
                ->lockForUpdate()
                ->with(['convocation', 'parallel'])
                ->findOrFail($syllabus->id);

            if (! in_array($locked->estado, self::RESETTABLE_STATES, true)) {
                throw ValidationException::withMessages([
                    'syllabus' => $locked->estado === 'aprobado'
                        ? 'Un sílabo aprobado no se reinicia. Si tiene un error, elimínelo: el docente empieza de nuevo.'
                        : 'El sílabo todavía no tiene trabajo que descartar.',
                ]);
            }
            if (! in_array($locked->convocation->estado, [Convocation::STATE_OPEN, Convocation::STATE_PAUSED], true)) {
                throw ValidationException::withMessages([
                    'syllabus' => 'La convocatoria ya no admite cambios en sus expedientes.',
                ]);
            }

            $parallel = $locked->parallel;

            $discarded = [
                'previous_state' => $locked->estado,
                'discarded_values' => $locked->values()->count(),
                'reason' => $reason,
            ];

            FieldValue::query()->where('silabo_id', $locked->id)->delete();
            DB::statement("SET LOCAL app.reinicio_institucional = 'on'");
            $discarded['discarded_signatures'] = DB::table('firmas_silabo')->where('silabo_id', $locked->id)->delete();
            DB::statement("SET LOCAL app.reinicio_institucional = 'off'");

            $locked->update([
                'estado' => 'sin_iniciar',
                'version_bloqueo' => 0,
                'iniciado_en' => null,
                'guardado_en' => null,
                'contexto_academico' => $this->academicContext->build($parallel, $locked->convocation->process->academicPeriod),
            ]);
            $this->inherit->execute(
                $locked,
                SyllabusTemplate::institutional()->sections->flatMap(fn ($section) => $section->blocks->flatMap(fn ($block) => $block->fields)),
                $parallel,
            );

            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole->id,
                action: 'silabo.reiniciado',
                resourceType: 'silabo',
                resourceId: $locked->id,
                result: 'exito',
                metadata: $discarded,
            );
            $this->notifications->execute(
                syllabus: $locked,
                eventType: 'silabo.reiniciado',
                deduplicationKey: 'silabo.reiniciado:'.$locked->id.':'.now()->timestamp,
                recipientIds: $this->recipients->teachersFor($locked),
                revisionNumber: null,
                reason: $reason,
            );

            return $locked->refresh();
        });
    }
}
