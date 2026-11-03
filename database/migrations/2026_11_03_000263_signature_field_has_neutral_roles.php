<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lo mismo que 000259 en el contenido del campo de firmas (`definiciones_campo`), que es lo que muestran
 * el editor y el sílabo: 000259 solo cambió la copia de `plantilla_silabo`. Sin «Nombre:» y con cargos neutros.
 * Solo toca las celdas que tienen la variable del nombre de quien firma y su cargo; no cuenta como edición
 * de la plantilla (no cambia plantilla_modificada_en). Los sílabos ya enviados (revisiones_silabo) no se tocan.
 */
return new class extends Migration
{
    private const LABEL = 'Nombre:';

    /** @var array<string, array{string, string}> variable => [cargo anterior, cargo neutro] */
    private const ROLES = [
        'nombre_docente' => ['Profesor – Investigador', 'Docente – Investigador/a'],
        'nombre_coordinador' => ['Coordinador de Carrera', 'Coordinación de Carrera'],
        'nombre_bibliotecario' => ['Bibliotecario', 'Biblioteca'],
    ];

    public function up(): void
    {
        $this->rewrite(true);
    }

    public function down(): void
    {
        $this->rewrite(false);
    }

    private function rewrite(bool $up): void
    {
        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'plantilla_modificada_en']) as $template) {
            $touched = false;
            $fields = DB::table('definiciones_campo')->where('plantilla_id', $template->plantilla_id)
                ->get(['definicion_campo_id', 'contenido_definicion_campo']);
            foreach ($fields as $field) {
                $content = json_decode((string) $field->contenido_definicion_campo, true);
                if (! is_array($content)) {
                    continue;
                }
                $changed = $this->walk($content, $up);
                if ($changed === $content) {
                    continue;
                }
                DB::table('definiciones_campo')->where('definicion_campo_id', $field->definicion_campo_id)
                    ->update(['contenido_definicion_campo' => json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
                $touched = true;
            }
            // El trigger marcar_cambio_definicion_campo_plantilla la marca como editada: se deja la fecha que tenía.
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
    private function walk(array $node, bool $up): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $up);
            }
        }
        if (! isset($node['content']) || ! is_array($node['content']) || ! array_is_list($node['content'])) {
            return $node;
        }
        $variable = $this->signerVariable($node['content']);
        if ($variable === null) {
            return $node;
        }
        [$old, $new] = self::ROLES[$variable];
        // Solo la celda de firma: tiene el nombre y, debajo, el cargo. Otras celdas con el mismo nombre no se tocan.
        $texts = array_map(fn ($child) => $this->onlyText($child), $node['content']);
        if (! in_array($old, $texts, true) && ! in_array($new, $texts, true)) {
            return $node;
        }
        $paragraphs = [];
        foreach ($node['content'] as $child) {
            $text = $this->onlyText($child);
            if ($up && $text === self::LABEL) {
                continue;
            }
            if (! $up && $this->variableOf($child) === $variable && ! in_array(self::LABEL, array_map(fn ($p) => $this->onlyText($p), $paragraphs), true)) {
                $paragraphs[] = $this->paragraphLike($child, self::LABEL);
            }
            if ($text === ($up ? $old : $new)) {
                $child = $this->paragraphLike($child, $up ? $new : $old);
            }
            $paragraphs[] = $child;
        }
        $node['content'] = $paragraphs;

        return $node;
    }

    /** @param  list<mixed>  $children */
    private function signerVariable(array $children): ?string
    {
        foreach ($children as $child) {
            $variable = $this->variableOf($child);
            if ($variable !== null && isset(self::ROLES[$variable])) {
                return $variable;
            }
        }

        return null;
    }

    private function variableOf(mixed $paragraph): ?string
    {
        $inline = is_array($paragraph) && ($paragraph['type'] ?? null) === 'paragraph' ? ($paragraph['content'] ?? []) : [];
        if (! is_array($inline) || count($inline) !== 1 || ($inline[0]['type'] ?? null) !== 'variable') {
            return null;
        }
        $id = $inline[0]['attrs']['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    private function onlyText(mixed $paragraph): ?string
    {
        $inline = is_array($paragraph) && ($paragraph['type'] ?? null) === 'paragraph' ? ($paragraph['content'] ?? []) : [];
        if (! is_array($inline) || count($inline) !== 1 || ($inline[0]['type'] ?? null) !== 'text') {
            return null;
        }
        $text = $inline[0]['text'] ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $paragraph
     * @return array<string, mixed>
     */
    private function paragraphLike(array $paragraph, string $text): array
    {
        return ['type' => 'paragraph', 'attrs' => $paragraph['attrs'] ?? ['textAlign' => 'left'], 'content' => [['type' => 'text', 'text' => $text]]];
    }
};
