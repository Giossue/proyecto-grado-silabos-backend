<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Presentation\Http\Requests\ImportTemplateStructureRequest;

final class TemplateDraftProblems
{
    public function __construct(private readonly TemplateStructureValidator $structure) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{path: string, message: string, blocking: bool}>
     */
    public function of(array $payload, ?TemplateDefinition $definition = null): array
    {
        $definition ??= SyllabusTemplate::query()->first()?->definition() ?? TemplateContext::active();
        $problems = [];
        foreach ($this->structure->draftProblems($definition, is_array($payload['sections'] ?? null) ? $payload['sections'] : []) as $problem) {
            $problems[] = [...$problem, 'blocking' => false];
        }
        foreach (ImportTemplateStructureRequest::errorsFor($payload) as $path => $messages) {
            foreach ($messages as $message) {
                $problems[] = ['path' => $path, 'message' => $message, 'blocking' => true];
            }
        }

        return $problems;
    }
}
