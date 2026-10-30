<?php

namespace App\Modules\Syllabus\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\Actions\CreateConvocation;
use App\Modules\Syllabus\Application\Actions\TransitionConvocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Presentation\Http\Requests\StoreConvocationRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\TransitionConvocationRequest;
use App\Modules\Syllabus\Presentation\Http\Requests\ViewConvocationsRequest;
use App\Support\InstitutionDate;
use App\Support\Search\AccentInsensitive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConvocationController extends Controller
{
    public function index(ViewConvocationsRequest $request, ActiveRole $roles): JsonResponse
    {
        $careerId = $roles->resolve($request)?->carrera_id;
        $filters = $request->validated();
        $search = is_string($filters['q'] ?? null) ? trim($filters['q']) : null;
        $state = in_array($filters['state'] ?? null, ['sin_iniciar', 'preparacion', 'abierta', 'pausada', 'cerrada'], true)
            ? $filters['state']
            : null;

        $payload = [
            'filters' => ['q' => $search ?: null, 'state' => $state],
            'convocations' => SyllabusProcess::query()
                ->when($search, fn ($query, string $term) => $query->where(
                    fn ($outer) => $outer->whereHas('academicPeriod', fn ($period) => $period
                        ->whereRaw(AccentInsensitive::matches('codigo_periodo_academico'), [AccentInsensitive::pattern($term)])),
                ))
                ->when($state, function ($query, string $value) use ($careerId): void {
                    if ($value === 'sin_iniciar') {
                        $query->whereDoesntHave('convocations', fn ($career) => $career->where('carrera_id', $careerId));
                    } else {
                        $query->whereHas('convocations', fn ($career) => $career->where('carrera_id', $careerId)->where('estado', $value));
                    }
                })
                ->with([
                    'academicPeriod:id,codigo',
                    'convocations' => fn ($career) => $career->where('carrera_id', $careerId)->withCount('syllabi'),
                ])
                ->orderByDesc('inicia_en')
                ->paginate(20)
                ->withQueryString()
                ->through(function (SyllabusProcess $process): array {
                    $convocation = $process->convocations->first();

                    return [
                        'id' => $convocation?->id,
                        'process_id' => $process->id,
                        'name' => $process->nombre,
                        'state' => $convocation->estado ?? 'sin_iniciar',
                        'process_state' => $process->estado,
                        'period' => $process->academicPeriod->codigo,
                        'template' => SyllabusTemplate::INSTITUTIONAL_NAME,
                        'syllabi_count' => $convocation->syllabi_count ?? 0,
                    ];
                }),
        ];

        return response()->json($payload);
    }

    public function store(StoreConvocationRequest $request, CreateConvocation $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $convocation = $action->execute($request->convocationData(), $actor, $request);

        return response()->json([
            'message' => 'Convocatoria iniciada: se generó un sílabo por cada paralelo.',
            'convocation_id' => $convocation->id,
        ], 201);
    }

    public function show(Convocation $convocation, Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('view', $convocation) === true, 403);
        $convocation->load(['career', 'process.academicPeriod']);
        $syllabi = $convocation->syllabi()
            ->with(['parallel:id,codigo,asignatura_id', 'parallel.subject:id,nombre,codigo_asignatura', 'teachers:id,nombre'])
            ->get()
            ->sortBy(fn ($syllabus): string => $syllabus->subject->nombre.'|'.$syllabus->academicParallelCode())
            ->values();

        $payload = [
            'convocation' => [
                'id' => $convocation->id,
                'name' => $convocation->nombre,
                'state' => $convocation->estado,
                'process' => [
                    'name' => $convocation->process->nombre,
                    'state' => $convocation->process->estado,
                ],
                'period' => $convocation->process->academicPeriod->codigo,
                'template' => SyllabusTemplate::INSTITUTIONAL_NAME,
                'start_date' => InstitutionDate::dateOf($convocation->process->inicia_en),
                'draft_deadline' => InstitutionDate::dateOf($convocation->process->entrega_en),
                'counts' => [
                    'total' => $syllabi->count(),
                    'not_started' => $syllabi->where('estado', 'sin_iniciar')->count(),
                    'draft' => $syllabi->where('estado', 'borrador')->count(),
                    'in_review' => $syllabi->where('estado', 'en_revision')->count(),
                    'approved' => $syllabi->where('estado', 'aprobado')->count(),
                ],
                'syllabi' => $syllabi->map(fn (Syllabus $syllabus) => [
                    'id' => $syllabus->id,
                    'subject' => $syllabus->academicSubjectName(),
                    'code' => $syllabus->academicSubjectCode(),
                    'state' => $syllabus->estado,
                    'iniciado_en' => $syllabus->iniciado_en?->toIso8601String(),
                    'parallels' => [$syllabus->academicParallelCode()],
                    'teachers' => $syllabus->teachers->pluck('nombre')->unique()->values(),
                ])->values(),
            ],
        ];

        return response()->json($payload);
    }

    public function transition(
        Convocation $convocation,
        string $transition,
        TransitionConvocationRequest $request,
        TransitionConvocation $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($convocation, $transition, $actor, $request);

        $message = $transition === TransitionConvocation::PAUSE
            ? 'Convocatoria en pausa. Los docentes de la carrera no editan ni envían; la malla queda editable.'
            : 'Convocatoria reanudada. Los docentes vuelven a trabajar, los paralelos nuevos ya tienen su sílabo y la malla queda protegida.';

        return response()->json(['message' => $message]);
    }
}
