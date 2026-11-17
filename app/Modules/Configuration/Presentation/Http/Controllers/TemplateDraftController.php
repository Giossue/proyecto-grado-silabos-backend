<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuration\Application\TemplateDraftProblems;
use App\Modules\Configuration\Application\TemplateDraftRehearsal;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Presentation\Http\Requests\ImportTemplateStructureRequest;
use App\Modules\Configuration\Presentation\Http\Requests\ManageTemplatesRequest;
use Illuminate\Http\JsonResponse;

class TemplateDraftController extends Controller
{
    public function preview(SyllabusTemplate $template, ImportTemplateStructureRequest $request, TemplateDraftRehearsal $rehearsal): JsonResponse
    {
        return response()->json($rehearsal->preview($template, $this->sections($request), $request->definition()));
    }

    public function impact(SyllabusTemplate $template, ImportTemplateStructureRequest $request, TemplateDraftRehearsal $rehearsal): JsonResponse
    {
        return response()->json($rehearsal->impact($template, $this->sections($request), $request->definition()));
    }

    public function check(SyllabusTemplate $template, ManageTemplatesRequest $request, TemplateDraftProblems $problems): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        return response()->json(['problems' => $problems->of($payload)]);
    }

    /**
     * @return list<array{key: string, title: string, orientation?: string, fields?: list<array{key: string, label?: string|null, quien_llena: string, content: array<string, mixed>}>}>
     */
    private function sections(ImportTemplateStructureRequest $request): array
    {
        /** @var list<array{key: string, title: string, orientation?: string, fields?: list<array{key: string, label?: string|null, quien_llena: string, content: array<string, mixed>}>}> $sections */
        $sections = $request->validated('sections');

        return $sections;
    }
}
