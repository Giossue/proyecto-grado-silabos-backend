<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Configuration\Application\Actions\ClearSyllabusTemplate;
use App\Modules\Configuration\Application\Actions\CreateSyllabusTemplate;
use App\Modules\Configuration\Application\Actions\FormatSyllabusTemplate;
use App\Modules\Configuration\Application\Actions\ReplaceTemplateStructure;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Configuration\Application\OfficialTemplate;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\TemplateAppearance;
use App\Modules\Configuration\Domain\TemplateTitleBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateBlock;
use App\Modules\Configuration\Infrastructure\Persistence\Models\TemplateSection;
use App\Modules\Configuration\Presentation\Http\Requests\CreateTemplateRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ImportTemplateStructureRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ManageTemplatesRequest;
use App\Modules\Configuration\Presentation\Http\Requests\UploadInstitutionLogoRequest;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Http\JsonResponse;

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

        return response()->json(['message' => 'Plantilla restablecida al formato oficial.']);
    }

    public function clear(SyllabusTemplate $template, ManageTemplatesRequest $request, ClearSyllabusTemplate $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($template, $actor, $request);

        return response()->json(['message' => 'Plantilla vaciada.']);
    }

    public function exportJson(SyllabusTemplate $template, ManageTemplatesRequest $request): JsonResponse
    {
        return response()->json([
            'version' => ImportTemplateStructureRequest::FORMAT_VERSION,
            'title' => TemplateTitleBlock::fromMapping($template->mapeo_documento)['text'],
            'layout' => TemplateAppearance::layout($template->mapeo_documento['layout'] ?? null),
            'sections' => $template->sections->map(fn (TemplateSection $section): array => [
                'key' => $section->clave,
                'title' => $section->titulo,
                'orientation' => $section->orientacion,
                'fields' => $section->blocks->flatMap(fn (TemplateBlock $block) => $block->fields->map(fn (FieldDefinition $field): array => [
                    'key' => $field->clave,
                    'label' => $field->etiqueta,
                    'quien_llena' => $field->quien_llena,
                    'content' => DocumentHtml::toLines($field->contenido),
                ])->all())->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function importJson(SyllabusTemplate $template, ImportTemplateStructureRequest $request, ReplaceTemplateStructure $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var list<array<string, mixed>> $sections */
        $sections = $request->validated('sections');
        $action->execute($template, $sections, $actor, $request);
        $mapping = $template->refresh()->mapeo_documento ?? [];
        $title = $request->validated('title');
        if (is_string($title) && trim($title) !== '') {
            $mapping['title_block'] = ['text' => trim($title)];
        }
        $layout = $request->validated('layout');
        if (is_array($layout)) {
            $mapping['layout'] = TemplateAppearance::layout([...TemplateAppearance::layout($mapping['layout'] ?? null), ...$layout]);
        }
        $template->forceFill(['mapeo_documento' => $mapping])->save();

        return response()->json(['message' => 'Plantilla importada desde el JSON.']);
    }

    public function uploadInstitutionLogo(SyllabusTemplate $template, UploadInstitutionLogoRequest $request, InstitutionalLogos $logos): JsonResponse
    {
        $logos->storeInstitution($request->file('logo'));

        return response()->json(['message' => 'Logo actualizado.', 'logo' => route('logos.institution', ['v' => $logos->version($logos->institutionPath())])]);
    }

    public function show(SyllabusTemplate $template, ManageTemplatesRequest $request, ProcessLocks $locks, InstitutionalLogos $logos): JsonResponse
    {
        $payload = $this->builderProps($template, $locks, $logos);

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
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
            'presets' => OfficialTemplate::presets(),
            'blocks' => ['identificacion' => ['table' => DocumentBlock::identificationLayout(array_column(TemplateVariables::catalog(), 'sample', 'key'))]],
            'logos' => [
                'institution' => route('logos.institution', ['v' => $logos->version($logos->institutionPath())]),
                'faculty' => asset('images/silabo/facultad.jpeg').'?v='.$logos->version(public_path('images/silabo/facultad.jpeg')),
                'institution_size' => InstitutionalLogos::INSTITUTION,
                'faculty_size' => InstitutionalLogos::FACULTY,
            ],
            'template' => [
                'id' => $template->id,
                'name' => SyllabusTemplate::INSTITUTIONAL_NAME,
                'appearance' => TemplateAppearance::fromMapping($template->mapeo_documento),
                'layout' => TemplateAppearance::layout($template->mapeo_documento['layout'] ?? null),
                'layoutOptions' => TemplateAppearance::catalog(),
                'titleBlock' => TemplateTitleBlock::fromMapping($template->mapeo_documento),
                'sections' => $template->sections->map(fn (TemplateSection $section) => [
                    'id' => $section->id,
                    'key' => $section->clave,
                    'title' => $section->titulo,
                    'orientation' => $section->orientacion,
                    'fields' => $section->blocks->flatMap(fn (TemplateBlock $block) => $block->fields->map(fn (FieldDefinition $field): array => [
                        'id' => $field->id,
                        'key' => $field->clave,
                        'label' => $field->etiqueta,
                        'quien_llena' => $field->quien_llena,
                        'content' => $field->contenido,
                    ])->all())->values()->all(),
                ])->values()->all(),
            ],
        ];
    }
}
