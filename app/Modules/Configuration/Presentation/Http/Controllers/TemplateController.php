<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\Actions\CreateSyllabusTemplate;
use App\Modules\Configuration\Application\Actions\DeleteTemplateBlock;
use App\Modules\Configuration\Application\Actions\DeleteTemplateSection;
use App\Modules\Configuration\Application\Actions\FormatSyllabusTemplate;
use App\Modules\Configuration\Application\Actions\ReorderTemplateBlocks;
use App\Modules\Configuration\Application\Actions\ReorderTemplateSections;
use App\Modules\Configuration\Application\Actions\SaveFieldDefinition;
use App\Modules\Configuration\Application\Actions\SaveTemplateAppearance;
use App\Modules\Configuration\Application\Actions\SaveTemplateDocument;
use App\Modules\Configuration\Application\Actions\SaveTemplateSection;
use App\Modules\Configuration\Application\Actions\SaveTemplateTitle;
use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Application\FieldOptions;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\SectionDesigns;
use App\Modules\Configuration\Application\SyllabusTemplateCatalog;
use App\Modules\Configuration\Application\TemplateDocumentDefaults;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Configuration\Presentation\Http\Requests\CreateTemplateRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ManageTemplatesRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ReorderTemplateBlocksRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ReorderTemplateSectionsRequest;
use App\Modules\Configuration\Presentation\Http\Requests\SaveFieldDefinitionRequest;
use App\Modules\Configuration\Presentation\Http\Requests\SaveTemplateAppearanceRequest;
use App\Modules\Configuration\Presentation\Http\Requests\SaveTemplateDocumentRequest;
use App\Modules\Configuration\Presentation\Http\Requests\SaveTemplateSectionRequest;
use App\Modules\Configuration\Presentation\Http\Requests\SaveTemplateTitleRequest;
use App\Modules\Configuration\Presentation\Http\Requests\StoreInstitutionLogoRequest;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\IdentificationCard;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class TemplateController extends Controller
{
    public function index(ManageTemplatesRequest $request, ProcessLocks $locks, InstitutionalLogos $logos): JsonResponse
    {
        $template = SyllabusTemplate::query()->first();
        if ($template !== null) {
            return response()->json($this->builderProps($template, $locks, $logos));
        }

        $payload = [
            'processLock' => $locks->templateLockReason(),
            'processLockDetail' => $locks->templateLock(),
            'template' => null,
        ];

        return response()->json($payload);
    }

    public function updateDocument(SyllabusTemplate $template, string $block, SaveTemplateDocumentRequest $request, SaveTemplateDocument $action): JsonResponse
    {
        $block = $template->blockOrFail($block);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($block, $request->validated('document'), $request->validated('fingerprint'), $actor, $request);

        return $this->success($request, 'Diseño de la plantilla guardado.');
    }

    public function store(CreateTemplateRequest $request, CreateSyllabusTemplate $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $official = $request->boolean('official');
        $template = $action->execute($actor, $request, $official);

        return response()->json([
            'message' => $official ? 'Plantilla creada con el formato oficial.' : 'Plantilla vacía creada.',
            'template_id' => $template->id,
        ], 201);
    }

    public function format(SyllabusTemplate $template, ManageTemplatesRequest $request, FormatSyllabusTemplate $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($template, $actor, $request);

        return response()->json(['message' => 'Formato oficial aplicado.']);
    }

    public function show(SyllabusTemplate $template, ManageTemplatesRequest $request, ProcessLocks $locks, InstitutionalLogos $logos): JsonResponse
    {
        $payload = $this->builderProps($template, $locks, $logos);

        return response()->json($payload);
    }

    /** @return array<string, mixed> */
    private function builderProps(SyllabusTemplate $template, ProcessLocks $locks, InstitutionalLogos $logos): array
    {
        $pausedProcess = SyllabusProcess::query()
            ->where('estado', SyllabusProcess::STATE_PAUSED)
            ->first(['id', 'pausado_en']);
        $templateChangedDuringPause = $pausedProcess?->pausado_en !== null
            && $template->plantilla_modificada_en !== null
            && $template->plantilla_modificada_en->greaterThan($pausedProcess->pausado_en);

        return [
            'processLock' => $locks->templateLockReason(),
            'processLockDetail' => $locks->templateLock(),
            'templateChangedDuringPause' => $templateChangedDuringPause,
            'variables' => TemplateVariables::catalog(),
            'identificationDesign' => TemplateDocumentDefaults::identification(),
            'logos' => [
                'institution' => route('logos.institution', ['v' => $logos->version($logos->institutionPath())]),
                'faculty' => asset('images/silabo/facultad.jpeg').'?v='.$logos->version(public_path('images/silabo/facultad.jpeg')),
                'institution_size' => InstitutionalLogos::INSTITUTION,
                'faculty_size' => InstitutionalLogos::FACULTY,
            ],
            'template' => [
                'id' => $template->id,
                'name' => SyllabusTemplate::INSTITUTIONAL_NAME,
                'description' => null,
                'appearance' => TemplateAppearance::fromMapping($template->mapeo_documento),
                'titleBlock' => TemplateTitleBlock::fromMapping($template->mapeo_documento),
                'sections' => $template->sections->map(fn (TemplateSection $section) => [
                    'id' => $section->id,
                    'key' => $section->clave,
                    'title' => $section->titulo,
                    'description' => $section->descripcion,
                    'blocks' => $section->blocks->map(function (TemplateBlock $block): array {
                        $fields = $block->fields;

                        return [
                            'id' => $block->id,
                            'key' => $block->clave,
                            'title' => $block->titulo,
                            'type' => $block->tipo,
                            'content_type' => $block->contentType(),
                            'page_orientation' => $block->pageOrientation(),
                            'compact' => $block->isCompact(),
                            'document' => $block->configuracion['document'] ?? null,
                            'fingerprint' => SaveTemplateDocument::fingerprint($block),
                            'fields' => $fields
                                ->reject(fn (FieldDefinition $field) => isset($block->configuracion['detached_fields'][$field->clave]))
                                ->map(fn (FieldDefinition $field) => [
                                    'id' => $field->id,
                                    'block_id' => $block->id,
                                    'key' => $field->clave,
                                    'label' => $field->etiqueta,
                                    'help' => $field->ayuda,
                                    'type' => $field->tipo,
                                    'config' => $field->typeConfig(),
                                    // La hoja del constructor dibuja la tabla oficial con datos de ejemplo.
                                    'document' => $field->tipo === 'automatico' && ($field->typeConfig()['source'] ?? null) === 'identification_card'
                                        ? IdentificationCard::document(IdentificationCard::sample())
                                        : null,
                                    'options' => FieldOptions::options($field),
                                    'show_when' => FieldConditions::normalize($field->mostrar_si),
                                    'required' => true,
                                    'inherited' => $field->heredado,
                                    'master_source' => $field->origen_maestro,
                                    'teacher_editable' => $field->editable_docente,
                                    'content_type' => $block->contentType(),
                                    'initial_content' => $block->configuracion['initial_content'] ?? null,
                                ])->values()->all(),
                        ];
                    })->values()->all(),
                ])->values()->all(),
            ],
            'presets' => app(SyllabusTemplateCatalog::class)->options(),
            'sectionDesigns' => app(SectionDesigns::class)->options($template),
            'blockTypes' => [
                ['value' => 'free_text', 'label' => 'Texto libre'],
                ['value' => 'template', 'label' => 'Personalizado'],
            ],
            'appearanceOptions' => TemplateAppearance::catalog(),
        ];
    }

    public function updateAppearance(
        SyllabusTemplate $template,
        SaveTemplateAppearanceRequest $request,
        SaveTemplateAppearance $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($template, $request->safe()->except('confirm_purge'), $actor, $request);

        return $this->success($request, 'Apariencia de la plantilla guardada.');
    }

    public function updateTitle(
        SyllabusTemplate $template,
        SaveTemplateTitleRequest $request,
        SaveTemplateTitle $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($template, $request->string('title')->toString(), $actor, $request);

        return $this->success($request, 'Título de la plantilla actualizado.');
    }

    public function storeField(
        SyllabusTemplate $template,
        SaveFieldDefinitionRequest $request,
        SaveFieldDefinition $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->create($template->id, $request->validated(), $actor, $request);

        return $this->success($request, 'Campo agregado a la sección.');
    }

    public function updateField(
        SyllabusTemplate $template,
        FieldDefinition $field,
        SaveFieldDefinitionRequest $request,
        SaveFieldDefinition $action,
    ): JsonResponse {
        abort_unless($field->plantilla_id === $template->id, 404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->update($field, $request->validated(), $actor, $request);

        return $this->success($request, 'Campo actualizado.');
    }

    public function storeSection(
        SyllabusTemplate $template,
        SaveTemplateSectionRequest $request,
        SaveTemplateSection $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->create($template->id, $request->validated(), $actor, $request);

        return $this->success($request, 'Sección agregada al borrador.');
    }

    public function updateSection(
        SyllabusTemplate $template,
        string $section,
        SaveTemplateSectionRequest $request,
        SaveTemplateSection $action,
    ): JsonResponse {
        $section = $template->sectionOrFail($section);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->update($section, $request->validated(), $actor, $request);

        return $this->success($request, 'Sección actualizada.');
    }

    public function reorderBlocks(
        SyllabusTemplate $template,
        ReorderTemplateBlocksRequest $request,
        ReorderTemplateBlocks $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute(
            $template,
            $request->string('section_id')->toString(),
            $request->collect('block_ids')->filter(fn (mixed $id): bool => is_string($id))->values()->all(),
            $actor,
            $request,
        );

        return $this->success($request, 'Orden de campos actualizado.');
    }

    public function reorderSections(
        SyllabusTemplate $template,
        ReorderTemplateSectionsRequest $request,
        ReorderTemplateSections $action,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute(
            $template,
            $request->collect('section_ids')->filter(fn (mixed $id): bool => is_string($id))->values()->all(),
            $actor,
            $request,
        );

        return $this->success($request, 'Orden de secciones actualizado.');
    }

    public function storeLogo(
        StoreInstitutionLogoRequest $request,
        InstitutionalLogos $logos,
        RecordAuditEvent $audit,
        ActiveRole $roles,
        ProcessLocks $locks,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $file = $request->file('logo');
        abort_unless($file instanceof UploadedFile, 422);
        $locks->assertTemplateEditable();
        DB::transaction(function () use ($actor, $audit, $file, $logos, $request, $roles): void {
            $logos->storeInstitution($file);
            $template = SyllabusTemplate::query()->first();
            $audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $roles->resolve($request)?->id,
                action: 'institucion.logo_actualizado',
                resourceType: 'plantilla_silabo',
                resourceId: $template?->id,
                result: 'exito',
            );
        });

        return $this->success($request, 'Logo de la universidad actualizado.');
    }

    public function destroyBlock(
        SyllabusTemplate $template,
        string $block,
        ManageTemplatesRequest $request,
        DeleteTemplateBlock $action,
    ): JsonResponse {
        $block = $template->blockOrFail($block);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($block, $actor, $request);

        return $this->success($request, 'Campo eliminado.');
    }

    public function destroySection(
        SyllabusTemplate $template,
        string $section,
        ManageTemplatesRequest $request,
        DeleteTemplateSection $action,
    ): JsonResponse {
        $section = $template->sectionOrFail($section);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($section, $actor, $request);

        return $this->success($request, 'Sección eliminada.');
    }

    private function success(Request $request, string $message): JsonResponse
    {
        return response()->json(['message' => $message]);
    }
}
