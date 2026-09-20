<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('fuentes_academicas')->select(['id', 'documento_fuente_academica'])->orderBy('id')->cursor() as $source) {
            $document = is_string($source->documento_fuente_academica)
                ? json_decode($source->documento_fuente_academica, true, 512, JSON_THROW_ON_ERROR)
                : $source->documento_fuente_academica;

            if (! is_array($document)) {
                continue;
            }

            $changed = false;
            $repaired = $this->repairNode($document, $changed);
            if (! $changed || $repaired === null) {
                continue;
            }

            DB::table('fuentes_academicas')->where('id', $source->id)->update([
                'documento_fuente_academica' => json_encode(
                    $repaired,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ]);
        }

        DB::statement(<<<'SQL'
            ALTER TABLE fuentes_academicas
            ADD CONSTRAINT fuentes_academicas_sin_texto_vacio_check
            CHECK (NOT jsonb_path_exists(
                documento_fuente_academica,
                'strict $.** ? (@.type == "text" && @.text == "")'
            ))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fuentes_academicas DROP CONSTRAINT IF EXISTS fuentes_academicas_sin_texto_vacio_check');

        // Empty text nodes were invalid ProseMirror data and cannot be restored safely.
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function repairNode(array $node, bool &$changed): ?array
    {
        if (($node['type'] ?? null) === 'text' && ($node['text'] ?? null) === '') {
            $changed = true;

            return null;
        }

        if (! is_array($node['content'] ?? null)) {
            return $node;
        }

        $content = [];
        foreach ($node['content'] as $child) {
            if (! is_array($child)) {
                $content[] = $child;

                continue;
            }

            $repaired = $this->repairNode($child, $changed);
            if ($repaired !== null) {
                $content[] = $repaired;
            }
        }

        if ($content === []) {
            unset($node['content']);
        } else {
            $node['content'] = $content;
        }

        return $node;
    }
};
