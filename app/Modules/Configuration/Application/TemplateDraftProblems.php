<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\DocumentHtml;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\ResponseKind;
use App\Modules\Configuration\Domain\TemplateDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Configuration\Presentation\Http\Requests\ImportTemplateStructureRequest;
use Illuminate\Validation\ValidationException;

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
        if (array_filter($problems, fn (array $problem): bool => $problem['blocking']) === []) {
            foreach (is_array($payload['sections'] ?? null) ? $payload['sections'] : [] as $sectionIndex => $section) {
                foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $fieldIndex => $field) {
                    if (! is_array($field) || ($field['quien_llena'] ?? null) !== FieldDefinition::INSTITUTIONAL || ! array_key_exists('content', $field)) {
                        continue;
                    }
                    $encoded = (string) json_encode($field['content']);
                    if (! str_contains($encoded, 'data-response') && ! str_contains($encoded, '"type":"response"')) {
                        continue;
                    }
                    try {
                        ResponseKind::forField($field['quien_llena'], FieldDocument::normalize(is_array($field['content']) && ! array_is_list($field['content']) ? $field['content'] : DocumentHtml::fromHtml($field['content'])));
                    } catch (ValidationException $exception) {
                        foreach ($exception->errors() as $messages) {
                            foreach ($messages as $message) {
                                $problems[] = ['path' => "sections.{$sectionIndex}.fields.{$fieldIndex}.content", 'message' => $message, 'blocking' => true];
                            }
                        }
                    }
                }
            }
        }

        return $problems;
    }
}
