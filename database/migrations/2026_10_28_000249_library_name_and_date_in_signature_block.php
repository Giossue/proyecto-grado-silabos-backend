<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La columna de Biblioteca del cuadro de firmas mostraba textos fijos («Nombre en la firma
 * electrónica», «Fecha … en la firma electrónica») porque el nombre y la fecha salían de la
 * firma del PDF. Ahora salen de la aprobación: se agregan las variables y se cambian esos
 * textos en la plantilla guardada, sin marcarla como modificada.
 */
return new class extends Migration
{
    private const VARIABLES = [
        'nombre_bibliotecario' => ['label' => 'Nombre de quien verificó la bibliografía', 'source' => 'approval.librarian', 'sample' => 'Lic. Nombre Apellido'],
        'fecha_verificacion' => ['label' => 'Fecha de verificación de biblioteca', 'source' => 'approval.verified_on', 'sample' => '14-08-2026'],
    ];

    private const NAME_TEXTS = ['Nombre en la firma electrónica'];

    private const DATE_TEXTS = ['Fecha de verificación en la firma electrónica', 'Fecha en la firma electrónica'];

    public function up(): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla', 'plantilla_modificada_en']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            if (! is_array($definition)) {
                continue;
            }
            $changed = false;
            if (is_array($definition['variables'] ?? null)) {
                foreach (self::VARIABLES as $key => $variable) {
                    if (! array_key_exists($key, $definition['variables'])) {
                        $definition['variables'][$key] = $variable;
                        $changed = true;
                    }
                }
            }
            $definition = $this->rewrite($definition, $changed);

            $fields = DB::table('definiciones_campo')->where('plantilla_id', $template->plantilla_id)
                ->whereRaw("contenido_definicion_campo::text LIKE '%en la firma electr%'")
                ->get(['definicion_campo_id', 'contenido_definicion_campo']);
            foreach ($fields as $field) {
                $fieldChanged = false;
                $content = $this->rewrite(json_decode((string) $field->contenido_definicion_campo, true), $fieldChanged);
                if ($fieldChanged) {
                    DB::table('definiciones_campo')->where('definicion_campo_id', $field->definicion_campo_id)
                        ->update(['contenido_definicion_campo' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            }

            if ($changed) {
                DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                    ->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            // Es una corrección del cuadro de firmas, no un cambio de diseño: no debe pedir rehacer sílabos.
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['plantilla_modificada_en' => $template->plantilla_modificada_en]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: los textos anteriores eran marcadores sin datos.
    }

    private function rewrite(mixed $node, bool &$changed): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if (array_is_list($node)) {
            $result = [];
            foreach ($node as $child) {
                foreach ($this->replaceParagraph($child, $changed) as $item) {
                    $result[] = $this->rewrite($item, $changed);
                }
            }

            return $result;
        }
        foreach ($node as $key => $value) {
            $node[$key] = $this->rewrite($value, $changed);
        }

        return $node;
    }

    /** @return list<mixed> */
    private function replaceParagraph(mixed $node, bool &$changed): array
    {
        $text = is_array($node) && ($node['type'] ?? null) === 'paragraph' && count($node['content'] ?? []) === 1
            && ($node['content'][0]['type'] ?? null) === 'text' ? trim((string) $node['content'][0]['text']) : null;
        $variable = fn (string $id): array => ['type' => 'variable', 'attrs' => ['id' => $id, 'label' => $id]];
        $marks = $node['content'][0]['marks'] ?? null;
        $plain = fn (string $value): array => array_filter(['type' => 'text', 'text' => $value, 'marks' => $marks], fn ($item) => $item !== null);

        if (in_array($text, self::NAME_TEXTS, true)) {
            $changed = true;

            return [
                [...$node, 'content' => [$plain('Nombre:')]],
                [...$node, 'content' => [$variable('nombre_bibliotecario')]],
            ];
        }
        if (in_array($text, self::DATE_TEXTS, true)) {
            $changed = true;

            return [[...$node, 'content' => [$plain('Fecha de verificación: '), $variable('fecha_verificacion')]]];
        }

        return [$node];
    }
};
