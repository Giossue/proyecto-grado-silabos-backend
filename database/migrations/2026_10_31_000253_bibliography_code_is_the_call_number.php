<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * En el sílabo de referencia la columna «Código» de la bibliografía lleva la signatura del libro
 * en la biblioteca (o «e-libro» en los digitales, que no están en Koha). El buscador la llena con
 * la signatura que da el catálogo. Solo se completa donde el bloque oficial tiene su columna
 * `codigo` y todavía no la enlazaba; es un ajuste, no un cambio de diseño.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla', 'plantilla_modificada_en']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            $block = $definition['blocks']['bibliografia'] ?? null;
            $list = is_array($block) ? ($block['catalog']['list'] ?? null) : null;
            if (! is_string($list)
                || ! is_array($block['catalog']['map'] ?? null)
                || array_key_exists('call_number', $block['catalog']['map'])
                || ! isset($block['value'][$list]['items']['properties']['codigo'])) {
                continue;
            }
            $definition['blocks']['bibliografia']['catalog']['map']['call_number'] = 'codigo';
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['definicion_plantilla' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            // Ajuste sin cambio de diseño: no debe pedir rehacer sílabos.
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['plantilla_modificada_en' => $template->plantilla_modificada_en]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: enlazar una columna más no se deshace.
    }
};
