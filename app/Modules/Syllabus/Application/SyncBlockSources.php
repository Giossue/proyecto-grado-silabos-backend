<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Domain\Blocks\BlockSources;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;

/**
 * Copia en los bloques del sílabo los datos `from` de sus campos de Carrera.
 * El servidor los impone en cada cambio; el docente no puede reemplazarlos.
 */
final class SyncBlockSources
{
    public function execute(Syllabus $syllabus): void
    {
        $definition = TemplateContext::active();
        $sources = $definition->sourceFields();
        if ($sources === []) {
            return;
        }
        $values = FieldValue::query()->where('silabo_id', $syllabus->id)->get();
        $fields = FieldDefinition::query()->whereIn('id', $values->pluck('definicion_campo_id'))->get()->keyBy('id');
        $tables = [];
        foreach ($values as $value) {
            $field = $fields->get($value->definicion_campo_id);
            if ($field instanceof FieldDefinition && in_array($field->clave, $sources, true)) {
                $tables[$field->clave] = BlockSources::rows(is_array($value->valor) ? $value->valor : $field->contenido);
            }
        }
        foreach ($values as $value) {
            $field = $fields->get($value->definicion_campo_id);
            if (! $field instanceof FieldDefinition || in_array($field->clave, $sources, true) || ! is_array($value->valor)) {
                continue;
            }
            $filled = BlockSources::fill($value->valor, $definition->blocks(), $tables);
            if ($filled !== $value->valor) {
                $value->update(['valor' => $filled]);
            }
        }
    }
}
