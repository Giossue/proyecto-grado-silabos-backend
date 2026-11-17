<?php

namespace App\Modules\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuration\Domain\Blocks\BlockDefinition;
use App\Modules\Configuration\Domain\Blocks\RepeatableTable;
use App\Modules\Configuration\Domain\Blocks\ValueSchema;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Presentation\Http\Requests\DesignRepeatableTableRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class TemplateBlockController extends Controller
{
    public function design(SyllabusTemplate $template, DesignRepeatableTableRequest $request): JsonResponse
    {
        $definition = $template->definition();
        $design = RepeatableTable::withKeys($request->design());
        $kind = (string) $design['kind'];
        if ($request->boolean('new') && $definition->block($kind) !== null) {
            throw ValidationException::withMessages(['kind' => 'Ya existe una tabla con ese nombre interno.']);
        }
        $variables = array_keys($definition->variables());
        $locked = RepeatableTable::locked($kind, $definition->block($kind), $variables);
        if ($locked !== null) {
            throw ValidationException::withMessages(['kind' => $locked]);
        }
        $block = BlockDefinition::define($kind, RepeatableTable::build($design), $variables);
        $read = RepeatableTable::read($kind, $block, $variables);

        return response()->json([
            'kind' => $kind,
            'definition' => $block,
            'design' => $read['design'] ?? $design,
            'initial' => ValueSchema::initial($block['value'], []),
        ]);
    }
}
