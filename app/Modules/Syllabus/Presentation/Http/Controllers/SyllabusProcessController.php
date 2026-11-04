<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Syllabus\Application\Actions\CreateSyllabusProcess;
use App\Modules\Syllabus\Application\Actions\DeleteSyllabusProcess;
use App\Modules\Syllabus\Application\Actions\ExtendProcessDeadline;
use App\Modules\Syllabus\Application\Actions\TransitionSyllabusProcess;
use App\Modules\Syllabus\Application\DeadlineExtensions;
use App\Modules\Syllabus\Application\Queries\ProcessSyllabusCounts;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Presentation\Http\Requests\DeleteSyllabusProcessRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ExtendProcessDeadlineRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ManageSyllabusProcessesRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\StoreSyllabusProcessRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\TransitionSyllabusProcessRequest;
use App\Support\InstitutionDate;
use Illuminate\Http\JsonResponse;

class SyllabusProcessController extends Controller
{
    public function index(ManageSyllabusProcessesRequest $request): JsonResponse
    {
        $template = SyllabusTemplate::query()->first();
        $activeProcess = SyllabusProcess::query()->inProgress()
            ->with('academicPeriod:id,codigo')
            ->first(['id', 'periodo_academico_id', 'estado']);

        $payload = [
            'processes' => SyllabusProcess::query()
                ->with('academicPeriod:id,codigo,fecha_fin')
                ->withCount('convocations')
                ->orderByDesc('inicia_en')
                ->get()
                ->map(fn (SyllabusProcess $process): array => [
                    'id' => $process->id,
                    'name' => $process->nombre,
                    'state' => $process->estado,
                    'template' => SyllabusTemplate::INSTITUTIONAL_NAME,
                    'period_id' => $process->periodo_academico_id,
                    'period_name' => $process->academicPeriod->codigo,
                    'starts_at' => InstitutionDate::dateOf($process->inicia_en),
                    'due_at' => InstitutionDate::dateOf($process->entrega_en),
                    'convocations_count' => $process->convocations_count,
                    'configurable' => $process->isConfigurable(),
                    'deletable' => $process->isDeletable(),
                    'extensions_used' => $process->cantidad_prorrogas,
                    'extensions_max' => SyllabusProcess::MAX_EXTENSIONS,
                    'extension_limit_on' => DeadlineExtensions::limitOn($process),
                    'extension_block_reason' => DeadlineExtensions::blockReason($process),
                ]),
            'template' => $template?->hasStructure()
                ? SyllabusTemplate::INSTITUTIONAL_NAME
                : null,
            'periods' => AcademicPeriod::query()
                ->orderByDesc('fecha_inicio')
                ->get(['id', 'codigo']),
            'active_process' => $activeProcess === null ? null : [
                'name' => $activeProcess->nombre,
                'period' => $activeProcess->academicPeriod->codigo,
                'state' => $activeProcess->estado,
            ],
        ];

        return response()->json($payload);
    }

    public function store(StoreSyllabusProcessRequest $request, CreateSyllabusProcess $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($request->processData(), $actor, $request);

        return response()->json([
            'message' => 'Convocatoria institucional abierta. Las coordinaciones ya pueden iniciar sus convocatorias.',
        ], 201);
    }

    public function impact(SyllabusProcess $process, ManageSyllabusProcessesRequest $request, ProcessSyllabusCounts $counts): JsonResponse
    {
        $syllabi = $counts->byState($process);

        return response()->json(['syllabi' => $syllabi, 'total' => array_sum($syllabi)]);
    }

    public function destroy(
        SyllabusProcess $process,
        DeleteSyllabusProcessRequest $request,
        DeleteSyllabusProcess $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($process, $actor, $request, $request->notice());

        return response()->json(['message' => 'Convocatoria institucional eliminada.']);
    }

    public function extendDeadline(
        SyllabusProcess $process,
        ExtendProcessDeadlineRequest $request,
        ExtendProcessDeadline $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $result = $action->execute(
            $process,
            $request->string('due_at')->toString(),
            $actor,
            $request,
        );

        if ($result['confirmation_required']) {
            return response()->json([
                'message' => 'La plantilla cambió después de finalizar. Confirme para adaptar los sílabos del período: se conserva lo escrito y los enviados o aprobados vuelven a borrador.',
                'code' => 'plantilla_cambiada',
                'counts' => $result['counts'],
            ], 422);
        }

        return response()->json(['message' => $result['reopened']
            ? 'Plazo prorrogado. La convocatoria se volvió a abrir.'
            : 'Plazo prorrogado para todas las convocatorias del proceso.']);
    }

    public function transition(
        SyllabusProcess $process,
        string $transition,
        TransitionSyllabusProcessRequest $request,
        TransitionSyllabusProcess $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $result = $action->execute($process, $transition, $actor, $request);

        if ($result['confirmation_required']) {
            return response()->json([
                'message' => 'La plantilla cambió durante la pausa. Confirme para adaptar los sílabos del período: se conserva lo escrito y los enviados o aprobados vuelven a borrador.',
                'code' => 'plantilla_cambiada',
                'counts' => $result['counts'],
            ], 422);
        }

        $message = match ($transition) {
            TransitionSyllabusProcess::PAUSE => 'Proceso en pausa. Los envíos se detienen y la plantilla queda editable.',
            TransitionSyllabusProcess::RESUME => 'Proceso reanudado. Los envíos vuelven a admitirse.',
            default => 'Convocatoria cerrada. Sus sílabos quedan solo para consulta.',
        };

        return response()->json(['message' => $message]);
    }
}
