<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEPENDENTS = ['discapacidad_tipo', 'discapacidad_adaptacion'];

    public function up(): void
    {
        if (! Schema::hasColumn('definiciones_campo', 'mostrar_si')) {
            Schema::table('definiciones_campo', fn ($table) => $table->jsonb('mostrar_si')->nullable());
        }

        DB::transaction(function (): void {
            $parents = DB::table('definiciones_campo')
                ->where('clave_definicion_campo', 'discapacidad_tiene')
                ->get(['plantilla_id', 'opciones_definicion_campo']);
            foreach ($parents as $parent) {
                $options = json_decode((string) $parent->opciones_definicion_campo, true);
                $values = array_map(
                    fn (mixed $option): mixed => is_array($option) ? ($option['value'] ?? null) : $option,
                    is_array($options) ? $options : [],
                );
                if (! in_array('Sí', $values, true)) {
                    continue;
                }
                DB::table('definiciones_campo')
                    ->where('plantilla_id', $parent->plantilla_id)
                    ->whereIn('clave_definicion_campo', self::DEPENDENTS)
                    ->whereNull('mostrar_si')
                    ->update(['mostrar_si' => json_encode(['key' => 'discapacidad_tiene', 'value' => 'Sí'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('definiciones_campo', 'mostrar_si')) {
            Schema::table('definiciones_campo', fn ($table) => $table->dropColumn('mostrar_si'));
        }
    }
};
