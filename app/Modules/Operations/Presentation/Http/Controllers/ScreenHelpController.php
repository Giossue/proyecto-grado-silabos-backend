<?php

namespace App\Modules\Operations\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\DeleteScreenHelp;
use App\Modules\Operations\Application\Actions\SaveScreenHelp;
use App\Modules\Operations\Infrastructure\Persistence\Models\ScreenHelp;
use App\Modules\Operations\Presentation\Http\Requests\ManageScreenHelpRequest;
use App\Modules\Operations\Presentation\Http\Requests\ShowScreenHelpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ScreenHelpController extends Controller
{
    public function show(ShowScreenHelpRequest $request): JsonResponse
    {
        $help = ScreenHelp::query()->where('pantalla', $request->string('screen')->toString())->where('activa', true)->first();

        return response()->json(['help' => $help === null ? null : $this->payload($help)]);
    }

    public function index(ManageScreenHelpRequest $request): JsonResponse
    {
        return response()->json(['help' => ScreenHelp::query()->orderBy('pantalla')->get()->map(fn (ScreenHelp $help): array => $this->payload($help))->all()]);
    }

    public function update(ManageScreenHelpRequest $request, ActiveRole $roles, SaveScreenHelp $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $help = $action->execute(
            $actor,
            $roles->resolve($request),
            $request->string('screen')->toString(),
            trim($request->string('title')->toString()),
            trim($request->string('content')->toString()),
            $request->boolean('active'),
        );

        return response()->json(['help' => $this->payload($help)]);
    }

    public function destroy(ManageScreenHelpRequest $request, ActiveRole $roles, DeleteScreenHelp $action): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($actor, $roles->resolve($request), $request->string('screen')->toString());

        return response()->noContent();
    }

    /** @return array{screen: string, title: string, content: string, active: bool, updated_at: string} */
    private function payload(ScreenHelp $help): array
    {
        return [
            'screen' => $help->pantalla,
            'title' => $help->titulo_ayuda,
            'content' => $help->contenido_ayuda,
            'active' => $help->activa,
            'updated_at' => $help->actualizado_en->toIso8601String(),
        ];
    }
}
