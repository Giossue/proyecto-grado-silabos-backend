<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SECURITY = "Cambie su contraseña y administre la verificación en dos pasos. Para activarla o desactivarla se le pedirá su contraseña actual.\n\nGuarde los códigos de recuperación en un lugar seguro: le permiten entrar si pierde el acceso a su aplicación de autenticación.";

    public function up(): void
    {
        foreach ($this->texts() as $screen => $content) {
            DB::table('ayudas_pantalla')->where('pantalla', $screen)->whereNull('actualizado_por')
                ->update(['contenido_ayuda' => $content, 'editada_en_ayuda' => now()]);
        }
    }

    public function down(): void {}

    /** @return array<string, string> */
    private function texts(): array
    {
        return [
            '/admin/configuracion/seguridad' => self::SECURITY,
            '/coordinacion/configuracion/seguridad' => self::SECURITY,
            '/docente/configuracion/seguridad' => self::SECURITY,
            '/biblioteca/configuracion/seguridad' => self::SECURITY,
            '/coordinacion/revisiones' => "Lista de los sílabos de su carrera que los docentes ya enviaron. Coordinación revisa primero el contenido; cuando lo aprueba, el sílabo pasa a Biblioteca para revisar la bibliografía.\n\nBusque por asignatura o docente y abra un sílabo para revisarlo, aprobarlo o pedir correcciones.",
            '/coordinacion/revisiones/:revision' => "Aquí revisa el contenido enviado por el docente, sección por sección.\n\nPuede solicitar una corrección con un comentario para el docente o aprobar la revisión; al aprobarla, el sílabo pasa a Biblioteca. Una revisión enviada no se modifica: si el docente corrige, se crea una nueva revisión y el historial permite compararlas.\n\nUn sílabo aprobado no se modifica. Si tiene un error, puede eliminarlo con todo su historial y el docente empieza de cero.",
            '/coordinacion/materias-asignaciones' => "Aquí programa las materias del período en curso, crea sus paralelos y elige el docente de cada uno.\n\nSeleccione una o varias materias para agregarles paralelos. Un paralelo sin docente queda pendiente de asignación.\n\nSi asigna un docente nuevo a un paralelo, su sílabo empieza desde cero. Cambiar el código o la jornada de un paralelo con el sílabo enviado o aprobado también lo elimina; el sistema le pide confirmación antes.",
            '/docente/mis-silabos' => "Un sílabo por cada paralelo que usted dicta en el período. Pulse uno para empezar o continuar su elaboración.\n\nEstados: sin iniciar, borrador, en revisión, corrección solicitada y aprobado. Mientras está en revisión no se puede editar; si Coordinación o Biblioteca piden correcciones, vuelve a quedar editable.\n\nLos sílabos de períodos anteriores están en «Historial».",
            '/biblioteca/panel' => "Resumen de los sílabos aprobados por Coordinación que esperan la revisión de su bibliografía.\n\nUse los accesos del panel para ir a la lista de revisiones.",
            '/biblioteca/revisiones' => "Aquí llegan, de todas las carreras, los sílabos que Coordinación ya aprobó, para que verifique su bibliografía. Cuando usted aprueba la bibliografía, el sílabo queda aprobado.\n\nBusque por asignatura o filtre por estado y abra un sílabo para revisarlo.",
            '/biblioteca/revisiones/:revision' => "Revise los libros que propuso el docente: que estén en biblioteca, completos y bien citados.\n\nSi todo está bien, apruebe la bibliografía: el sílabo queda aprobado y lo firman docente, coordinación y biblioteca. Si hay un problema, devuélvalo al docente marcando los motivos; cuando lo corrija y reenvíe, vuelve a esta bandeja sin pasar de nuevo por Coordinación.",
        ];
    }
};
