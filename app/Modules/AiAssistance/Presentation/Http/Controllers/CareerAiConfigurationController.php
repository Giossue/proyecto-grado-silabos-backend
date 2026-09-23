<?php

namespace App\Modules\AiAssistance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Application\Actions\SaveCareerAiPreferences;
use App\Modules\AiAssistance\Application\ResolveEffectiveAiConfiguration;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\CareerAiPreference;
use App\Modules\AiAssistance\Presentation\Http\Requests\SaveCareerAiPreferencesRequest;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CareerAiConfigurationController extends Controller
{
    public function show(Request $request, ActiveRole $roles, ResolveEffectiveAiConfiguration $resolver): JsonResponse
    {
        $activeRole = $roles->resolve($request);
        abort_unless($activeRole?->role->codigo_rol === 'coordinador' && is_string($activeRole->carrera_id), 403);
        $template = SyllabusTemplate::query()->firstOrFail();
        $preferences = CareerAiPreference::query()->where('carrera_id', $activeRole->carrera_id)
            ->get()->keyBy('definicion_campo_id');
        $fields = [];
        foreach ($template->sections as $section) {
            foreach ($section->blocks as $block) {
                foreach ($block->fields as $field) {
                    $preference = $preferences->get($field->id);
                    $fields[] = [
                        'id' => $field->id,
                        'section' => $section->titulo,
                        'label' => $field->etiqueta,
                        'admin_default' => $field->ia_habilitada,
                        'coordinator_configurable' => $field->ia_coordinacion_configurable,
                        'override' => $preference !== null && $preference->revision_plantilla_origen === $template->revision_configuracion
                            ? $preference->ia_habilitada : null,
                        'effective' => $resolver->enabled($activeRole->carrera_id, $field),
                    ];
                }
            }
        }

        return response()->json(['template_revision' => $template->revision_configuracion, 'fields' => $fields]);
    }

    public function update(
        SaveCareerAiPreferencesRequest $request,
        ActiveRole $roles,
        SaveCareerAiPreferences $action,
    ): JsonResponse {
        $actor = $request->user();
        $activeRole = $roles->resolve($request);
        abort_unless($actor instanceof User && $activeRole?->role->codigo_rol === 'coordinador' && is_string($activeRole->carrera_id), 403);
        /** @var list<array{field_id: string, enabled: bool}> $preferences */
        $preferences = $request->validated('preferences');
        $action->execute($activeRole->carrera_id, $preferences, $actor);

        return response()->json(['message' => 'Configuración de IA guardada para la carrera.']);
    }

    public function destroy(Request $request, ActiveRole $roles, SaveCareerAiPreferences $action): JsonResponse
    {
        $actor = $request->user();
        $activeRole = $roles->resolve($request);
        abort_unless($actor instanceof User && $activeRole?->role->codigo_rol === 'coordinador' && is_string($activeRole->carrera_id), 403);
        $action->reset($activeRole->carrera_id, $actor);

        return response()->json(['message' => 'Se restablecieron los valores de Administración.']);
    }
}
