<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

use App\Modules\Configuration\Presentation\Http\Requests\ImportTemplateStructureRequest;
use Illuminate\Support\Facades\Validator;

final class TemplateDraftValidator
{
    /**
     * @return list<string>
     */
    public function errors(TemplateDraft $draft): array
    {
        $payload = $draft->payload();
        $request = ImportTemplateStructureRequest::create('/', 'PUT', $payload);
        $request->setContainer(app());
        $validator = Validator::make($payload, $request->rules(), [], $request->attributes());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }
        $errors = [];
        foreach ($validator->errors()->messages() as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "{$this->describe($path, $draft)}: {$message}";
            }
        }

        return array_values(array_unique([...$draft->structuralErrors(), ...$errors]));
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
