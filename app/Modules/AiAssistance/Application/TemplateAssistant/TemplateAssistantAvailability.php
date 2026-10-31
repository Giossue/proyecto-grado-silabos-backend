<?php

namespace App\Modules\AiAssistance\Application\TemplateAssistant;

final class TemplateAssistantAvailability
{
    public static function available(): bool
    {
        $driver = strtolower(trim((string) config('ai.driver')));
        if ($driver === 'baseline') {
            return true;
        }

        return $driver === 'deepseek'
            && filled(config('ai.hosted.base_url'))
            && filled(config('ai.template.model') ?: config('ai.hosted.model'))
            && filled(config('ai.hosted.api_key'));
    }
}
