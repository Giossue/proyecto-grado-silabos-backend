<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuration\Application\TemplateVersions;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Presentation\Http\Requests\ManageTemplatesRequest;
use Illuminate\Http\JsonResponse;

class TemplateVersionController extends Controller
{
    public function index(SyllabusTemplate $template, ManageTemplatesRequest $request, TemplateVersions $versions): JsonResponse
    {
        return response()->json(['versions' => $versions->list($template)]);
    }

    public function show(SyllabusTemplate $template, string $version, ManageTemplatesRequest $request, TemplateVersions $versions): JsonResponse
    {
        return response()->json($versions->show($versions->find($template, $version)));
    }

    public function comparison(SyllabusTemplate $template, string $version, ManageTemplatesRequest $request, TemplateVersions $versions): JsonResponse
    {
        return response()->json(['changes' => $versions->compare($template, $versions->find($template, $version))]);
    }
}
