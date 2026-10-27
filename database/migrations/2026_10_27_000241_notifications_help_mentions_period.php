<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ayudas_pantalla')
            ->whereIn('pantalla', ['/admin/notificaciones', '/coordinacion/notificaciones', '/docente/notificaciones', '/biblioteca/notificaciones'])
            ->whereNull('actualizado_por')
            ->update([
                'contenido_ayuda' => "Aquí aparecen los avisos del período en curso que le corresponden. Cuando el período termina, sus avisos ya no se muestran.\n\nPulse un aviso para ir a la pantalla relacionada; al abrirlo se marca como leído.",
                'editada_en_ayuda' => now(),
            ]);
    }

    public function down(): void {}
};
