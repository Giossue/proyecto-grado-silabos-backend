<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * En la tabla de identificación, «HORAS DEL PROFESOR» pasa a «HORAS DEL DOCENTE», que no marca género.
 * Cambia la plantilla guardada y el contenido de sus campos, solo donde el texto es exactamente ese.
 * No cuenta como edición de la plantilla: los triggers ponen plantilla_modificada_en y aquí se deja la
 * fecha que tenía, porque al reanudar una convocatoria esa fecha rehace los sílabos. Los sílabos ya
 * enviados (revisiones_silabo) no se tocan.
 */
return new class extends Migration
{
    private const OLD = 'HORAS DEL PROFESOR';

    private const NEW = 'HORAS DEL DOCENTE';

    public function up(): void
    {
        $this->rewrite(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rewrite(self::NEW, self::OLD);
    }

    private function rewrite(string $from, string $to): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla', 'plantilla_modificada_en']) as $template) {
            $touched = false;
            $definition = json_decode((string) $template->definicion_plantilla, true);
            if (is_array($definition) && ($changed = $this->walk($definition, $from, $to)) !== $definition) {
                DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                    ->update(['definicion_plantilla' => $this->encode($changed)]);
                $touched = true;
            }
            $fields = DB::table('definiciones_campo')->where('plantilla_id', $template->plantilla_id)
                ->get(['definicion_campo_id', 'contenido_definicion_campo']);
            foreach ($fields as $field) {
                $content = json_decode((string) $field->contenido_definicion_campo, true);
                if (! is_array($content) || ($changed = $this->walk($content, $from, $to)) === $content) {
                    continue;
                }
                DB::table('definiciones_campo')->where('definicion_campo_id', $field->definicion_campo_id)
                    ->update(['contenido_definicion_campo' => $this->encode($changed)]);
                $touched = true;
            }
            if ($touched) {
                DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                    ->update(['plantilla_modificada_en' => $template->plantilla_modificada_en]);
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function walk(array $node, string $from, string $to): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $from, $to);
            }
        }
        if (($node['type'] ?? null) === 'text' && ($node['text'] ?? null) === $from) {
            $node['text'] = $to;
        }

        return $node;
    }

    /** @param  array<mixed>  $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
