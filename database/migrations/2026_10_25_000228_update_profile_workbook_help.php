<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ayudas_pantalla')->where('pantalla', '/coordinacion/proyecto-carrera')->whereNull('actualizado_por')
            ->update(['contenido_ayuda' => "Las fichas contienen los campos que Administración asignó a Coordinación en la plantilla del sílabo. Pulse una materia para editar su ficha y elegir qué campos puede modificar el docente.\n\nCon «Importar desde Excel», descargue la plantilla de su carrera. Complete las celdas de contenido sin cambiar hojas, encabezados ni materias. Cargue el Excel, revise la vista previa y seleccione qué materias y campos aplicar. Las celdas vacías conservan los valores actuales. Esta carga no necesita IA ni OCR.\n\nCon una convocatoria abierta la edición está bloqueada. Si hay sílabos en curso, cambiar una ficha exige pausar y confirmar su regeneración; los procesos cerrados no se modifican.", 'editada_en_ayuda' => now()]);
    }

    public function down(): void {}
};
