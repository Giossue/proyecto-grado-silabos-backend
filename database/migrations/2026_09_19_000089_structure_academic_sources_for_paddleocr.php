<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuentes_academicas', function (Blueprint $table): void {
            $table->jsonb('documento_fuente_academica')->nullable()->after('contenido_fuente_academica');
            $table->unsignedSmallInteger('version_documento_fuente')->default(1)->after('documento_fuente_academica');
        });

        foreach (DB::table('fuentes_academicas')->select(['id', 'contenido_fuente_academica'])->orderBy('id')->cursor() as $source) {
            DB::table('fuentes_academicas')->where('id', $source->id)->update([
                'documento_fuente_academica' => json_encode(
                    $this->legacyDocument(is_string($source->contenido_fuente_academica) ? $source->contenido_fuente_academica : ''),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ]);
        }

        DB::statement("ALTER TABLE fuentes_academicas ALTER COLUMN documento_fuente_academica SET DEFAULT '{\"schemaVersion\":1,\"type\":\"doc\",\"content\":[]}'::jsonb");
        DB::statement('ALTER TABLE fuentes_academicas ALTER COLUMN documento_fuente_academica SET NOT NULL');
        DB::statement("ALTER TABLE fuentes_academicas ADD CONSTRAINT fuentes_academicas_documento_check CHECK (jsonb_typeof(documento_fuente_academica) = 'object' AND documento_fuente_academica->>'type' = 'doc' AND jsonb_typeof(documento_fuente_academica->'content') = 'array')");

        Schema::table('fragmentos_fuente_academica', function (Blueprint $table): void {
            $table->unsignedSmallInteger('pagina_inicio')->nullable()->after('titulo_fragmento');
            $table->unsignedSmallInteger('pagina_fin')->nullable()->after('pagina_inicio');
            $table->jsonb('bloques_origen')->nullable()->after('pagina_fin');
        });

        Schema::dropIfExists('extracciones_fuente_academica');
    }

    public function down(): void
    {
        Schema::create('extracciones_fuente_academica', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('fuente_academica_id')->constrained('fuentes_academicas')->restrictOnDelete();
            $table->foreignUuid('objeto_pdf_id')->constrained('objetos_almacenados')->restrictOnDelete();
            $table->foreignUuid('ejecucion_trabajo_id')->nullable()->unique('extraccion_fuente_ejecucion_unica')->constrained('ejecuciones_trabajo')->restrictOnDelete();
            $table->foreignUuid('solicitada_por')->constrained('usuarios')->restrictOnDelete();
            $table->string('estado_extraccion_fuente', 24)->default('pendiente');
            $table->string('modelo_ocr', 100);
            $table->string('version_adaptador', 80);
            $table->char('huella_pdf', 64);
            $table->jsonb('opciones_extraccion')->nullable();
            $table->jsonb('paginas_extraidas')->nullable();
            $table->jsonb('metadatos_respuesta')->nullable();
            $table->char('huella_contenido', 64)->nullable();
            $table->string('codigo_error', 100)->nullable();
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('solicitada_en');
            $table->timestampTz('iniciada_en')->nullable();
            $table->timestampTz('completada_en')->nullable();
            $table->timestampTz('fallida_en')->nullable();
            $table->unique(['fuente_academica_id', 'huella_pdf', 'modelo_ocr'], 'extraccion_fuente_idempotencia_unica');
            $table->index(['fuente_academica_id', 'estado_extraccion_fuente', 'solicitada_en']);
        });
        DB::statement("ALTER TABLE extracciones_fuente_academica ADD CONSTRAINT extracciones_fuente_estado_check CHECK (estado_extraccion_fuente IN ('pendiente', 'en_ejecucion', 'completada', 'fallida'))");

        Schema::table('fragmentos_fuente_academica', function (Blueprint $table): void {
            $table->dropColumn(['pagina_inicio', 'pagina_fin', 'bloques_origen']);
        });
        DB::statement('ALTER TABLE fuentes_academicas DROP CONSTRAINT IF EXISTS fuentes_academicas_documento_check');
        Schema::table('fuentes_academicas', function (Blueprint $table): void {
            $table->dropColumn(['documento_fuente_academica', 'version_documento_fuente']);
        });
    }

    /** @return array{schemaVersion: int, type: string, content: list<array<string, mixed>>} */
    private function legacyDocument(string $markdown): array
    {
        $content = [];
        foreach (preg_split('/\n{2,}/u', trim(str_replace(["\r\n", "\r"], "\n", $markdown))) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+)$/us', $block, $match) === 1) {
                $content[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => strlen($match[1])],
                    'content' => [['type' => 'text', 'text' => trim($match[2])]],
                ];
            } else {
                $content[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $block]]];
            }
        }

        return ['schemaVersion' => 1, 'type' => 'doc', 'content' => $content];
    }
};
