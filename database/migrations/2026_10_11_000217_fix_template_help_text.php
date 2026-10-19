<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONTENT = "Es el formato único del sílabo: sus secciones, sus campos y el orden en que aparecen. Aquí también se configura el logo institucional.\n\nCon una convocatoria abierta la plantilla está bloqueada. Para cambiarla, pause la convocatoria. Al reanudar se le pedirá confirmación: todos los sílabos de este período se borran y empiezan desde cero, incluso los aprobados, porque el formato es único para toda la universidad.\n\nLos sílabos de períodos anteriores no se tocan. Si la plantilla está vacía, puede cargar el formato oficial de la UEB.";

    public function up(): void
    {
        DB::table('ayudas_pantalla')
            ->where('pantalla', '/admin/plantilla')
            ->whereNull('actualizado_por')
            ->update(['contenido_ayuda' => self::CONTENT]);
    }

    public function down(): void {}
};
