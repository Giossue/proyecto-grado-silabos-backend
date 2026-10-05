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
        Schema::table('valores_campo', function (Blueprint $table): void {
            $table->foreignUuid('asignatura_id')->nullable()->constrained('asignaturas', 'asignatura_id')->cascadeOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE valores_campo ALTER COLUMN silabo_id DROP NOT NULL;
            ALTER TABLE valores_campo DROP CONSTRAINT valores_campo_silabo_id_definicion_campo_id_unique;
            ALTER TABLE valores_campo ADD CONSTRAINT valores_campo_un_solo_dueno_check
                CHECK ((silabo_id IS NULL) <> (asignatura_id IS NULL));
            CREATE UNIQUE INDEX valores_campo_silabo_definicion_unica
                ON valores_campo (silabo_id, definicion_campo_id) WHERE silabo_id IS NOT NULL;
            CREATE UNIQUE INDEX valores_campo_asignatura_definicion_unica
                ON valores_campo (asignatura_id, definicion_campo_id) WHERE asignatura_id IS NOT NULL;
            ALTER TABLE definiciones_campo DROP CONSTRAINT definiciones_campo_heredado_check;
            ALTER TABLE definiciones_campo ADD CONSTRAINT definiciones_campo_heredado_check
                CHECK (NOT heredado_definicion_campo OR origen_dato_definicion_campo IS NOT NULL);
            SQL);

        $columns = [
            'descripcion' => ['descripcion', 'descripcion_asignatura'],
            'objetivo_general' => ['objetivo_general'],
            'objetivos_especificos' => ['objetivos_especificos'],
            'resultados_aprendizaje' => ['resultados_aprendizaje'],
            'habilidades_blandas' => ['habilidades_blandas'],
            'relacion_perfil_egreso' => ['contribucion_perfil'],
            'herramientas_tic' => ['herramientas_tic'],
            'bibliografia_basica' => ['bibliografia'],
            'bibliografia_complementaria' => ['bibliografia_complementaria'],
        ];

        DB::table('definiciones_campo')
            ->where('origen_dato_definicion_campo', 'like', 'fichas_asignatura.%')
            ->update(['origen_dato_definicion_campo' => 'ficha']);
        DB::table('definiciones_campo')
            ->whereIn('clave_definicion_campo', ['descripcion', 'descripcion_asignatura'])
            ->where('tipo_definicion_campo', 'texto_libre')
            ->update([
                'heredado_definicion_campo' => true,
                'origen_dato_definicion_campo' => 'ficha',
                'editable_docente_definicion_campo' => false,
            ]);

        foreach (DB::table('fichas_asignatura')->get() as $profile) {
            foreach ($columns as $column => $keys) {
                $value = $this->document($profile->{$column});
                $definition = DB::table('definiciones_campo')
                    ->whereIn('clave_definicion_campo', $keys)
                    ->where('origen_dato_definicion_campo', 'ficha')
                    ->value('definicion_campo_id');
                if ($value === null || $definition === null) {
                    continue;
                }
                DB::table('valores_campo')->insert([
                    'valor_campo_id' => (string) Str::uuid7(),
                    'asignatura_id' => $profile->asignatura_id,
                    'definicion_campo_id' => $definition,
                    'valor_campo' => json_encode($value, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        Schema::drop('fichas_asignatura');
    }

    public function down(): void
    {
        throw new RuntimeException('Migración irreversible: restaure el respaldo previo a I-189.');
    }

    /** @return array<string, mixed>|null */
    private function document(mixed $raw): ?array
    {
        $value = is_string($raw) ? (json_decode($raw, true) ?? $raw) : $raw;
        $lines = match (true) {
            is_string($value) => trim($value) === '' ? [] : [trim($value)],
            is_array($value) => array_values(array_filter(array_map(
                fn (mixed $item): string => is_array($item) ? implode(' — ', array_map('strval', array_values($item))) : trim((string) $item),
                $value,
            ), fn (string $line): bool => $line !== '')),
            default => [],
        };
        if ($lines === []) {
            return null;
        }
        $paragraph = fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        if (is_string($value)) {
            return ['type' => 'doc', 'content' => [$paragraph($lines[0])]];
        }

        return ['type' => 'doc', 'content' => [[
            'type' => 'bulletList',
            'content' => array_map(fn (string $line): array => ['type' => 'listItem', 'content' => [$paragraph($line)]], $lines),
        ]]];
    }
};
