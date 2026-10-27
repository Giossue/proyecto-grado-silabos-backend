<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ayudas_pantalla')->where('pantalla', '/biblioteca/panel')->delete();
        DB::table('ayudas_pantalla')->where('pantalla', '/biblioteca/revisiones')->whereNull('actualizado_por')->update([
            'contenido_ayuda' => "Arriba ve cuántos sílabos esperan su verificación, cuántos esperan su firma y cuántos devolvió con comentario.\n\nDebajo llegan, de todas las carreras, los sílabos que Coordinación ya aprobó, para que verifique su bibliografía. Cuando usted aprueba la bibliografía, el sílabo queda aprobado.\n\nBusque por asignatura o filtre por estado y abra un sílabo para revisarlo.",
            'editada_en_ayuda' => now(),
        ]);
    }

    public function down(): void {}
};
