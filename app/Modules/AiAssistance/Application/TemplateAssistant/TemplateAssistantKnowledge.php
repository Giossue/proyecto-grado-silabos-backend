<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

use RuntimeException;

final class TemplateAssistantKnowledge
{
    public const DIRECTORY = 'resources/ai/plantilla';

    public function system(): string
    {
        $files = glob(base_path(self::DIRECTORY.'/*.md')) ?: [];
        sort($files);
        $parts = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                throw new RuntimeException("No se pudo leer {$file}.");
            }
            $parts[] = trim($content);
        }
        if ($parts === []) {
            throw new RuntimeException('Falta el contexto del asistente en '.self::DIRECTORY.'.');
        }

        return implode("\n\n", $parts);
    }
}
