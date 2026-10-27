<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ayudas_pantalla')->where('pantalla', '/coordinacion/panel')->delete();
        DB::table('ayudas_pantalla')->insertOrIgnore([
            'ayuda_pantalla_id' => (string) Str::uuid(),
            'pantalla' => '/coordinacion/puesta-en-marcha',
            'titulo_ayuda' => 'Puesta en marcha de la carrera',
            'contenido_ayuda' => "Los pasos para preparar su carrera, en orden: armar la malla, programar las materias del período, crear los paralelos, asignar un docente a cada uno y abrir la convocatoria.\n\nAl abrir la convocatoria se crea un sílabo por paralelo con su docente. Se llega desde el botón «Puesta en marcha» de Revisión.",
            'activa' => true,
            'actualizado_por' => null,
            'editada_en_ayuda' => now(),
        ]);
        DB::table('ayudas_pantalla')->where('pantalla', '/coordinacion/revisiones')->whereNull('actualizado_por')->update([
            'contenido_ayuda' => "Arriba ve el avance de su carrera, cuántos sílabos esperan su revisión y cuántos no se han iniciado, y el aviso con el plazo de entrega. Desde aquí abre, pausa o reanuda la convocatoria de su carrera.\n\n«Por revisar» lista los sílabos que los docentes ya enviaron. Coordinación revisa primero el contenido; cuando lo aprueba, el sílabo pasa a Biblioteca para revisar la bibliografía. Abra uno para aprobarlo o pedir correcciones.\n\n«Seguimiento» muestra todos los sílabos de la convocatoria con su estado.",
            'editada_en_ayuda' => now(),
        ]);
    }

    public function down(): void {}
};
