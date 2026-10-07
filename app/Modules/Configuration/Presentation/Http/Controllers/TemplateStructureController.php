<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\Actions\DeleteTemplateBlock;
use App\Modules\Configuration\Application\Actions\DeleteTemplateSection;
use App\Modules\Configuration\Application\Actions\ReorderTemplateBlocks;
use App\Modules\Configuration\Application\Actions\ReorderTemplateSections;
use App\Modules\Configuration\Application\Actions\SaveTemplateSection;
use App\Modules\Configuration\Application\Actions\SaveTemplateStructure;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Presentation\Http\Requests\ManageTemplatesRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ReorderStructureFieldsRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ReorderTemplateSectionsRequest;
use App\Modules\Configuration\Presentation\Http\Requests\SaveStructureFieldRequest;
use App\Modules\Configuration\Presentation\Http\Requests\SaveStructureSectionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** API simplificada de la plantilla (I-195, etapa 3): secciones y campos con tipo y configuración. */
final class TemplateStructureController extends Controller
{
    public function storeSection(SyllabusTemplate $template, SaveStructureSectionRequest $request, SaveTemplateStructure $action): JsonResponse
    {
        $section = $action->createSection($template, $request->validated(), $this->actor($request), $request);

        return response()->json(['message' => 'Sección agregada a la plantilla.', 'section_id' => $section->id], 201);
    }

    public function updateSection(SyllabusTemplate $template, string $section, SaveStructureSectionRequest $request, SaveTemplateSection $action): JsonResponse
    {
        $section = $template->sectionOrFail($section);
        $action->update($section, $request->validated(), $this->actor($request), $request);

        return response()->json(['message' => 'Sección actualizada.']);
    }

    public function reorderSections(SyllabusTemplate $template, ReorderTemplateSectionsRequest $request, ReorderTemplateSections $action): JsonResponse
    {
        $action->execute(
            $template,
            $request->collect('section_ids')->filter(fn (mixed $id): bool => is_string($id))->values()->all(),
            $this->actor($request),
            $request,
        );

        return response()->json(['message' => 'Orden de secciones actualizado.']);
    }

    public function destroySection(SyllabusTemplate $template, string $section, ManageTemplatesRequest $request, DeleteTemplateSection $action): JsonResponse
    {
        $action->execute($template->sectionOrFail($section), $this->actor($request), $request);

        return response()->json(['message' => 'Sección eliminada.']);
    }

    public function storeField(SyllabusTemplate $template, string $section, SaveStructureFieldRequest $request, SaveTemplateStructure $action): JsonResponse
    {
        $template->sectionOrFail($section);
        $field = $action->createField($template, $section, $request->validated(), $this->actor($request), $request);

        return response()->json(['message' => 'Campo agregado a la sección.', 'field' => $this->payload($field)], 201);
    }

    public function updateField(SyllabusTemplate $template, FieldDefinition $field, SaveStructureFieldRequest $request, SaveTemplateStructure $action): JsonResponse
    {
        abort_unless($field->plantilla_id === $template->id, 404);
        $field = $action->updateField($template, $field, $request->validated(), $this->actor($request), $request);

        return response()->json(['message' => 'Campo actualizado.', 'field' => $this->payload($field)]);
    }

    public function reorderFields(SyllabusTemplate $template, string $section, ReorderStructureFieldsRequest $request, ReorderTemplateBlocks $action): JsonResponse
    {
        $template->sectionOrFail($section);
        $ids = $request->collect('field_ids')->filter(fn (mixed $id): bool => is_string($id))->values()->all();
        $blockIds = FieldDefinition::query()
            ->where('plantilla_id', $template->id)
            ->whereIn('definicion_campo_id', $ids)
            ->pluck('campo_plantilla_id', 'definicion_campo_id');
        if ($blockIds->count() !== count($ids)) {
            throw ValidationException::withMessages(['field_ids' => 'El nuevo orden no es válido. Recargue la página e intente otra vez.']);
        }
        $action->execute($template, $section, array_map(fn (string $id): string => (string) $blockIds[$id], $ids), $this->actor($request), $request);

        return response()->json(['message' => 'Orden de campos actualizado.']);
    }

    public function destroyField(SyllabusTemplate $template, FieldDefinition $field, ManageTemplatesRequest $request, DeleteTemplateBlock $action): JsonResponse
    {
        abort_unless($field->plantilla_id === $template->id, 404);
        $action->execute($template->blockOrFail($field->campo_plantilla_id), $this->actor($request), $request);

        return response()->json(['message' => 'Campo eliminado.']);
    }

    /** @return array<string, mixed> */
    private function payload(FieldDefinition $field): array
    {
        return [
            'id' => $field->id,
            'key' => $field->clave,
            'label' => $field->etiqueta,
            'help' => $field->ayuda,
            'type' => $field->fieldType()->key(),
            'config' => $field->typeConfig(),
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
