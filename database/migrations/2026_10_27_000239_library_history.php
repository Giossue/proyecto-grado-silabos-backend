<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ayudas_pantalla')->insertOrIgnore([
            'ayuda_pantalla_id' => (string) Str::uuid(),
            'pantalla' => '/biblioteca/historial',
            'titulo_ayuda' => 'Historial de biblioteca',
            'contenido_ayuda' => "Los sílabos de períodos terminados, en solo lectura. Aquí llegan solos cuando termina el período académico.\n\nFiltre por período, facultad o estado. «Solo los míos» muestra los que usted aprobó, devolvió o firmó.",
            'activa' => true,
            'actualizado_por' => null,
            'editada_en_ayuda' => now(),
        ]);
        DB::table('ayudas_pantalla')->where('pantalla', '/biblioteca/revisiones')->whereNull('actualizado_por')->update([
            'contenido_ayuda' => "Arriba ve cuántos sílabos esperan su verificación, cuántos esperan su firma y cuántos devolvió con comentario.\n\nDebajo llegan, de todas las carreras, los sílabos del período en curso que Coordinación ya aprobó, para que verifique su bibliografía. Cuando usted aprueba la bibliografía, el sílabo queda aprobado.\n\nFiltre por facultad si se reparten el trabajo: el sistema recuerda la que eligió. Los períodos terminados están en «Historial».",
            'editada_en_ayuda' => now(),
        ]);
    }

    public function down(): void {}
};
