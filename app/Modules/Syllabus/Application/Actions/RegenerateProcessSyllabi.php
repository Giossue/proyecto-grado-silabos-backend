<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Modules\Operations\Application\Actions\ScheduleWorkflowNotification;
use App\Modules\Operations\Application\WorkflowNotificationRecipients;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Support\Facades\DB;

/**
 * Adapta a la plantilla vigente los sílabos de las convocatorias abiertas o en pausa del proceso:
 * se conserva lo escrito que sigue encajando y los enviados o aprobados vuelven a borrador.
 */
final class RegenerateProcessSyllabi
{
    /** @var array<string, int> */
    private const EMPTY_COUNTS = [
        'sin_iniciar' => 0,
        'borrador' => 0,
        'en_revision' => 0,
        'correccion_solicitada' => 0,
        'aprobado' => 0,
    ];

    public function __construct(
        private readonly InProgressWork $work,
        private readonly WorkflowNotificationRecipients $recipients,
        private readonly ScheduleWorkflowNotification $notifications,
    ) {}

    /** @return array<string, int> */
    public function counts(SyllabusProcess $process): array
    {
        return $this->countSyllabi($process, toReopen: false);
    }

    /** @return array<string, int> */
    public function countsToReopen(SyllabusProcess $process): array
    {
        return $this->countSyllabi($process, toReopen: true);
    }

    /** @return array<string, int> */
    private function countSyllabi(SyllabusProcess $process, bool $toReopen): array
    {
        $counts = self::EMPTY_COUNTS;
        $query = DB::table('silabos')
            ->join('convocatorias_carreras', 'convocatorias_carreras.convocatoria_carrera_id', '=', 'silabos.convocatoria_carrera_id')
            ->where('convocatorias_carreras.convocatoria_universidad_id', $process->id);
        if ($toReopen) {
            $query->where('convocatorias_carreras.estado_convocatoria_carrera', Convocation::STATE_CLOSED)
                ->whereNotNull('convocatorias_carreras.estado_previo_cierre_convocatoria_carrera');
        } else {
            $query->whereIn('convocatorias_carreras.estado_convocatoria_carrera', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED]);
        }
        $rows = $query
            ->select('silabos.estado_silabo', DB::raw('count(*) as total'))
            ->groupBy('silabos.estado_silabo')
            ->get();

        foreach ($rows as $row) {
            $state = (string) $row->estado_silabo;
            $counts[$state] = (int) $row->total;
        }

        return $counts;
    }

    /** @return list<string> */
    public function regenerate(SyllabusProcess $process, string $changeKey): array
    {
        $convocations = Convocation::query()
            ->where('proceso_id', $process->id)
            ->whereIn('estado', [Convocation::STATE_OPEN, Convocation::STATE_PAUSED])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $syllabi = Syllabus::query()
            ->whereIn('convocatoria_id', $convocations->modelKeys())
            ->with(['convocation.process', 'parallel'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $teacherIds = $this->recipients->teachersForProcess($process);

        foreach ($syllabi as $syllabus) {
            $this->work->adaptToTemplate($syllabus);
        }

        $this->notifications->executeForResource(
            eventType: 'proceso.plantilla_rehecho',
            deduplicationKey: "proceso.plantilla_rehecho:{$process->id}:{$changeKey}",
            recipientIds: $teacherIds,
            revisionNumber: null,
            resourceId: $process->id,
            resourceType: 'proceso_silabos',
        );

        return $teacherIds;
    }
}
