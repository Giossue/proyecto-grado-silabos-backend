<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

use App\Modules\Configuration\Application\TemplateDraftProblems;

final class TemplateDraftValidator
{
    public function __construct(private readonly TemplateDraftProblems $problems) {}

    /**
     * @return list<string>
     */
    public function errors(TemplateDraft $draft): array
    {
        $errors = [];
        foreach ($this->problems->of($draft->payload(), $draft->definition()) as $problem) {
            $errors[] = $problem['blocking'] ? "{$this->describe($problem['path'], $draft)}: {$problem['message']}" : $problem['message'];
        }

        return array_values(array_unique($errors));
    }

    private function describe(string $path, TemplateDraft $draft): string
    {
        if (preg_match('/^sections\.(\d+)(?:\.fields\.(\d+))?/', $path, $match) !== 1) {
            return $path;
        }
        $section = $draft->payload()['sections'][(int) $match[1]] ?? null;
        if ($section === null) {
            return $path;
        }
        if (! isset($match[2])) {
            return "Sección «{$section['key']}»";
        }
        $field = $section['fields'][(int) $match[2]] ?? null;

        return $field === null ? "Sección «{$section['key']}»" : "Campo «{$field['key']}»";
    }
}
