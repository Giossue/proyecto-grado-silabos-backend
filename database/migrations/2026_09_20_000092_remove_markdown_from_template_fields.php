<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // La migración conserva los valores de los borradores; solo cambia el
        // discriminador de presentación a su equivalente actual.
        DB::table('definiciones_campo')
            ->where('tipo_definicion_campo', 'markdown')
            ->update(['tipo_definicion_campo' => 'texto_largo']);

        foreach (DB::table('componentes_plantilla')
            ->select(['id', 'configuracion_componente_plantilla'])
            ->whereNotNull('configuracion_componente_plantilla')
            ->orderBy('id')
            ->cursor() as $block) {
            $configuration = is_string($block->configuracion_componente_plantilla)
                ? json_decode($block->configuracion_componente_plantilla, true, 512, JSON_THROW_ON_ERROR)
                : $block->configuracion_componente_plantilla;
            if (! is_array($configuration)) {
                continue;
            }

            $changed = false;
            $normalized = $this->normalizeLegacyKinds($configuration, $changed);
            if (! $changed) {
                continue;
            }

            DB::table('componentes_plantilla')
                ->where('id', $block->id)
                ->update([
                    'configuracion_componente_plantilla' => json_encode(
                        $normalized,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                ]);
        }

        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_tipo_check');
        DB::statement(<<<'SQL'
            ALTER TABLE definiciones_campo
            ADD CONSTRAINT definiciones_campo_tipo_check
            CHECK (tipo_definicion_campo IN (
                'texto_corto', 'texto_largo', 'numero', 'fecha',
                'seleccion_unica', 'seleccion_multiple', 'booleano',
                'repetible', 'calculo', 'referencia_maestra'
            ))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE definiciones_campo DROP CONSTRAINT IF EXISTS definiciones_campo_tipo_check');
        DB::statement(<<<'SQL'
            ALTER TABLE definiciones_campo
            ADD CONSTRAINT definiciones_campo_tipo_check
            CHECK (tipo_definicion_campo IN (
                'texto_corto', 'texto_largo', 'markdown', 'numero', 'fecha',
                'seleccion_unica', 'seleccion_multiple', 'booleano',
                'repetible', 'calculo', 'referencia_maestra'
            ))
            SQL);

        // Los datos no vuelven a llamarse Markdown porque texto largo es el
        // equivalente canónico y no existe una forma segura de distinguirlos.
    }

    private function normalizeLegacyKinds(mixed $value, bool &$changed): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $child) {
            if ($key === 'kind' && $child === 'markdown') {
                $normalized[$key] = 'texto_largo';
                $changed = true;

                continue;
            }
            $normalized[$key] = $this->normalizeLegacyKinds($child, $changed);
        }

        return $normalized;
    }
};
