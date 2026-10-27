<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ayudas_pantalla')->where('pantalla', '/docente/panel')->delete();
        DB::table('ayudas_pantalla')->where('pantalla', '/docente/mis-silabos')->whereNull('actualizado_por')->update([
            'contenido_ayuda' => "Arriba ve cuántos sílabos le faltan por entregar, cuántos debe corregir y cuántos están en revisión, junto con el plazo de la convocatoria.\n\nDebajo, un sílabo por cada paralelo que usted dicta en el período. Pulse uno para empezar o continuar su elaboración.\n\nEstados: sin iniciar, borrador, en revisión, corrección solicitada y aprobado. Mientras está en revisión no se puede editar; si Coordinación o Biblioteca piden correcciones, vuelve a quedar editable.\n\nLos sílabos de períodos anteriores están en «Historial».",
            'editada_en_ayuda' => now(),
        ]);
    }

    public function down(): void {}
};
