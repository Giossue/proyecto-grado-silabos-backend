<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fragmentos_fuente_academica', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('fuente_academica_id')->constrained('fuentes_academicas')->cascadeOnDelete();
            $table->jsonb('ruta_encabezados');
            $table->string('titulo_fragmento', 180)->nullable();
            $table->unsignedSmallInteger('posicion_fragmento');
            $table->text('contenido_fragmento');
            $table->char('huella_fragmento', 64);

            $table->unique(['fuente_academica_id', 'posicion_fragmento'], 'fragmento_fuente_posicion_unica');
            $table->index('huella_fragmento', 'fragmento_fuente_huella_idx');
        });
        DB::statement(<<<'SQL'
            CREATE INDEX fragmento_fuente_busqueda_idx
            ON fragmentos_fuente_academica
            USING GIN (to_tsvector('spanish', coalesce(titulo_fragmento, '') || ' ' || contenido_fragmento))
            SQL);

        foreach (DB::table('fuentes_academicas')->orderBy('id')->get(['id', 'contenido_fuente_academica']) as $source) {
            foreach ($this->fragment((string) $source->contenido_fuente_academica) as $position => $fragment) {
                DB::table('fragmentos_fuente_academica')->insert([
                    'id' => (string) Str::uuid(),
                    'fuente_academica_id' => $source->id,
                    'ruta_encabezados' => json_encode($fragment['heading_path'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'titulo_fragmento' => $fragment['title'],
                    'posicion_fragmento' => $position,
                    'contenido_fragmento' => $fragment['content'],
                    'huella_fragmento' => hash('sha256', json_encode([
                        'content' => $fragment['content'],
                        'path' => $fragment['heading_path'],
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fragmentos_fuente_academica');
    }

    /** @return list<array{heading_path: list<string>, title: string|null, content: string}> */
    private function fragment(string $markdown): array
    {
        $markdown = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
        if ($markdown === '') {
            return [];
        }
        $path = [];
        $title = null;
        $body = [];
        $result = [];
        $flush = function () use (&$body, &$path, &$result, &$title): void {
            $content = trim(implode("\n", $body));
            while ($content !== '') {
                $chunk = trim(mb_substr($content, 0, 4000));
                if ($chunk !== '') {
                    $result[] = ['heading_path' => array_values($path), 'title' => $title, 'content' => $chunk];
                }
                $content = trim(mb_substr($content, 4000));
            }
            $body = [];
        };
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/u', trim($line), $matches) === 1) {
                $flush();
                $level = strlen($matches[1]);
                $title = mb_substr(trim($matches[2]), 0, 180);
                $path = array_slice($path, 0, $level - 1);
                $path[$level - 1] = $title;
            } else {
                $body[] = $line;
            }
        }
        $flush();

        return $result;
    }
};
