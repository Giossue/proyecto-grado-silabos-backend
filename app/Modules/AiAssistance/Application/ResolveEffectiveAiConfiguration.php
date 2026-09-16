<?php

namespace App\Modules\AiAssistance\Application;

use App\Modules\AiAssistance\Infrastructure\Persistence\Models\CareerAiPreference;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;

class ResolveEffectiveAiConfiguration
{
    public function enabled(string $careerId, FieldDefinition $field): bool
    {
        if (! $field->ia_coordinacion_configurable) {
            return $field->ia_habilitada;
        }
        $field->loadMissing('template');
        $preference = CareerAiPreference::query()
            ->where('carrera_id', $careerId)
            ->where('definicion_campo_id', $field->id)
            ->where('revision_plantilla_origen', $field->template->revision_configuracion)
            ->value('ia_habilitada');

        return is_bool($preference) ? $preference : $field->ia_habilitada;
    }
}
