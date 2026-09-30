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
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Presentation\Http\Requests\ExtendProcessDeadlineRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ManageSyllabusProcessesRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\StoreSyllabusProcessRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\TransitionSyllabusProcessRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SyllabusProcessController extends Controller
{
    public function index(
        ManageSyllabusProcessesRequest $request,
        DeleteSyllabusProcess $deleteProcess,
    ): JsonResponse {
        $template = SyllabusTemplate::query()->first();
        $activeProcess = SyllabusProcess::query()->inProgress()
            ->with('academicPeriod:id,codigo')
            ->first(['id', 'periodo_academico_id', 'estado']);

        $payload = [
            'processes' => SyllabusProcess::query()
                ->with('academicPeriod:id,codigo')
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
                    'starts_at' => $process->inicia_en->toIso8601String(),
                    'due_at' => $process->entrega_en->toIso8601String(),
                    'convocations_count' => $process->convocations_count,
                    'configurable' => $process->isConfigurable(),
                    'deletable' => $deleteProcess->isDeletable($process),
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

    public function destroy(
        SyllabusProcess $process,
        Request $request,
        DeleteSyllabusProcess $action,
    ): JsonResponse {
        Gate::authorize('delete', $process);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($process, $actor, $request);

        return response()->json(['message' => 'Convocatoria institucional eliminada.']);
    }

    public function extendDeadline(
        SyllabusProcess $process,
        ExtendProcessDeadlineRequest $request,
        ExtendProcessDeadline $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute(
            $process,
            $request->string('due_at')->toString(),
            $actor,
            $request,
        );

        return response()->json(['message' => 'Plazo prorrogado para todas las convocatorias del proceso.']);
    }

    public function transition(
        SyllabusProcess $process,
        string $transition,
        TransitionSyllabusProcessRequest $request,
        TransitionSyllabusProcess $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($process, $transition, $actor, $request);

        $message = match ($transition) {
            TransitionSyllabusProcess::PAUSE => 'Proceso en pausa. Los envíos se detienen y la plantilla queda editable.',
            TransitionSyllabusProcess::RESUME => 'Proceso reanudado. Los envíos vuelven a admitirse.',
            default => 'Proceso cerrado.',
        };

        return response()->json(['message' => $message]);
    }
}
