<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\Actions\CreateAcademicSource;
use App\Modules\Configuration\Application\Actions\UpdateAcademicSource;
use App\Modules\Configuration\Application\Actions\UpdateAcademicSourceContent;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Configuration\Presentation\Http\Requests\CreateSourceRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ManageAcademicSourceRequest;
use App\Modules\Configuration\Presentation\Http\Requests\UpdateSourceContentRequest;
use App\Modules\Configuration\Presentation\Http\Requests\UpdateSourceRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ViewSourcesRequest;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Syllabus\Application\ProcessLocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AcademicSourceController extends Controller
{
    public function index(ViewSourcesRequest $request, ActiveRole $roles, ProcessLocks $locks): Response|JsonResponse
    {
        $activeRole = $roles->resolve($request);

        $payload = [
            'processLock' => $locks->careerLockReason($activeRole?->carrera_id),
            'sources' => AcademicSource::query()
                ->where('carrera_id', $activeRole?->carrera_id)
                ->orderBy('nombre')
                ->get()
                ->map(fn (AcademicSource $source) => [
                    'id' => $source->id,
                    'name' => $source->nombre,
                    'description' => $source->descripcion,
                    'has_content' => is_string($source->contenido) && trim($source->contenido) !== '',
                ]),
        ];

        return $request->expectsJson() ? response()->json($payload) : Inertia::render('Sources/Index', $payload);
    }

    public function store(CreateSourceRequest $request, CreateAcademicSource $action): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $source = $action->execute($request->validated(), $actor, $request);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Fuente creada. Ya puede redactar su contenido.', 'source_id' => $source->id], 201);
        }

        return to_route('sources.show', $source)->with('success', 'Fuente creada. Ya puede redactar su contenido.');
    }

    public function show(AcademicSource $source, ManageAcademicSourceRequest $request, ProcessLocks $locks): Response|JsonResponse
    {
        $payload = [
            'processLock' => $locks->careerLockReason($source->carrera_id),
            'source' => [
                'id' => $source->id,
                'name' => $source->nombre,
                'description' => $source->descripcion,
                'content' => $source->contenido,
            ],
        ];

        return $request->expectsJson() ? response()->json($payload) : Inertia::render('Sources/Show', $payload);
    }

    public function update(
        AcademicSource $source,
        UpdateSourceRequest $request,
        UpdateAcademicSource $action,
    ): RedirectResponse|JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($source, $request->validated(), $actor, $request);

        $message = 'Fuente actualizada.';

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);
    }

    public function updateContent(
        AcademicSource $source,
        UpdateSourceContentRequest $request,
        UpdateAcademicSourceContent $action,
    ): RedirectResponse|JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $content = $request->validated('content');
        $action->execute($source, is_string($content) ? $content : null, $actor, $request);

        $message = 'Contenido guardado.';

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);
    }
}
