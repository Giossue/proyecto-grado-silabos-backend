<?php

namespace App\Modules\Syllabus\Application;

use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class SyllabusDocumentFields
{
    /**
     * @return Collection<int, FieldDefinition>
     */
    public static function fields(Syllabus $syllabus): Collection
    {
        $ids = $syllabus->values()->pluck('definicion_campo_id');
        $archived = FieldDefinition::withoutGlobalScopes()->whereIn('id', $ids)->where('vigente_en_plantilla', false)->exists();

        return $archived
            ? FieldDefinition::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('posicion')->get()
            : FieldDefinition::query()->orderBy('posicion')->get();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public static function archivedSections(Syllabus $syllabus): ?array
    {
        $fields = self::fields($syllabus);
        if (! $fields->contains(fn (FieldDefinition $field): bool => ! $field->getAttribute('vigente_en_plantilla'))) {
            return null;
        }
        $values = $syllabus->values()->get()->keyBy('definicion_campo_id');
        $structure = DB::table('campos_plantilla')->join('secciones_plantilla', 'campos_plantilla.seccion_plantilla_id', '=', 'secciones_plantilla.seccion_plantilla_id')
            ->whereIn('campo_plantilla_id', $fields->pluck('campo_plantilla_id'))->orderBy('posicion_seccion_plantilla')->orderBy('posicion_campo_plantilla')->get();
        $sections = [];
        foreach ($structure as $block) {
            $sectionId = $block->seccion_plantilla_id;
            $sections[$sectionId] ??= [
                'id' => $sectionId,
                'key' => $block->clave_seccion_plantilla,
                'title' => $block->titulo_seccion_plantilla,
                'orientation' => $block->orientacion_seccion_plantilla,
                'fields' => [],
            ];
            foreach ($fields->where('campo_plantilla_id', $block->campo_plantilla_id) as $field) {
                $stored = $values->get($field->id);
                $sections[$sectionId]['fields'][] = [
                    'definition_id' => $field->id,
                    'key' => $field->clave,
                    'label' => $field->etiqueta,
                    'help' => $field->ayuda,
                    'quien_llena' => $field->quien_llena,
                    'document' => FieldDocument::normalize($stored instanceof FieldValue ? $stored->valor : $field->contenido),
                ];
            }
        }

        return array_values($sections);
    }
}
