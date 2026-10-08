<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->texts() as $screen => [$title, $content]) {
            DB::table('ayudas_pantalla')->insertOrIgnore([
                'ayuda_pantalla_id' => (string) Str::uuid(),
                'pantalla' => $screen,
                'titulo_ayuda' => $title,
                'contenido_ayuda' => $content,
                'activa' => true,
                'actualizado_por' => null,
                'editada_en_ayuda' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ayudas_pantalla')
            ->whereIn('pantalla', array_keys($this->texts()))
            ->whereNull('actualizado_por')
            ->delete();
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function texts(): array
    {
        return [
            '/admin/panel' => ['Panel de administración', "Resumen de la puesta en marcha del sistema y de la convocatoria vigente. Los pasos pendientes enlazan a la pantalla donde se completan.\n\nUn orden recomendado: primero Facultades y carreras, luego Usuarios y roles, después la Plantilla del sílabo y por último abrir la convocatoria."],
            '/admin/usuarios' => ['Usuarios y roles', "Cree las cuentas de coordinación, docencia y biblioteca, una por una o importándolas desde Excel. Cada persona recibe un correo de invitación para verificar su correo y definir su contraseña.\n\nCoordinación y docencia se asignan a una carrera; biblioteca trabaja con toda la universidad. Una persona puede tener más de un rol: el «+1» junto al rol indica que tiene otros.\n\nPulse una fila para ver la ficha de la cuenta y use «Editar» para corregir sus datos, quitar un rol o agregar otro. Desde el menú de la fila puede desactivar o reactivar la cuenta, y reenviar la invitación si sigue pendiente. Al desactivar una cuenta, su sesión se cierra."],
            '/admin/estructura-academica' => ['Facultades y carreras', "Administre el catálogo académico: facultades (con su logo en PNG), carreras, coordinadores y períodos académicos. Use las pestañas para cambiar de catálogo.\n\nPuede crear, editar y activar o desactivar cada registro; un registro con historial no se elimina, solo se desactiva. Las carreras también se pueden importar desde Excel, y desde una carrera puede reemplazar a su coordinador.\n\nMientras haya una convocatoria en curso, algunos cambios quedan bloqueados; el aviso de la pantalla le dice qué hacer."],
            '/admin/estructura-academica/:section' => ['Facultades y carreras', "Administre el catálogo académico: facultades (con su logo en PNG), carreras, coordinadores y períodos académicos. Use las pestañas para cambiar de catálogo.\n\nPuede crear, editar y activar o desactivar cada registro; un registro con historial no se elimina, solo se desactiva. Las carreras también se pueden importar desde Excel, y desde una carrera puede reemplazar a su coordinador.\n\nMientras haya una convocatoria en curso, algunos cambios quedan bloqueados; el aviso de la pantalla le dice qué hacer."],
            '/admin/convocatorias' => ['Convocatorias', "Cada fila es un período lectivo y su convocatoria institucional: las fechas de clases y las fechas en que los docentes elaboran y entregan sus sílabos. Solo puede haber una convocatoria abierta o pausada a la vez.\n\nDesde el menú de la fila puede abrir, pausar, reanudar o cerrar la convocatoria, y extender el plazo de entrega. Cada coordinación inicia después la convocatoria de su carrera.\n\nMientras la convocatoria esté abierta, la plantilla del sílabo no se puede modificar; para corregirla, pause el proceso."],
            '/admin/plantilla' => ['Plantilla del sílabo', "Es el formato único del sílabo: sus secciones, sus campos y el orden en que aparecen. Aquí también se configura el logo institucional.\n\nCon una convocatoria abierta la plantilla está bloqueada. Si la pausa y corrige algo, se le pedirá confirmación porque los sílabos en curso que no están aprobados se rehacen; los aprobados se conservan.\n\nCada sílabo entregado guarda su propia copia de la plantilla, así que los cambios futuros no alteran lo ya enviado. Si la plantilla está vacía, puede cargar el formato oficial de la UEB."],
            '/admin/auditoria' => ['Auditoría', "Registro de las acciones importantes que se hacen en el sistema: quién las hizo, cuándo, con qué rol y con qué resultado.\n\nEs solo de consulta."],
            '/admin/ayuda' => ['Ayuda de pantallas', "Aquí escribe los textos que se muestran en el botón flotante de ayuda de cada pantalla. La lista incluye las pantallas de todos los roles.\n\nPulse una fila para editar su título y su texto. Separe los párrafos con una línea en blanco. Si desactiva «Mostrar ayuda en esta pantalla», el texto se conserva pero el botón no aparece."],
            '/admin/notificaciones' => ['Notificaciones', "Aquí aparecen los avisos del sistema que le corresponden.\n\nPulse un aviso para ir a la pantalla relacionada; al abrirlo se marca como leído."],
            '/admin/configuracion/perfil' => ['Perfil', 'Aquí puede actualizar su nombre y su correo electrónico.'],
            '/admin/configuracion/seguridad' => ['Seguridad', "Cambie su contraseña y administre la verificación en dos pasos. Para activarla o desactivarla se le pedirá su contraseña actual.\n\nTambién puede ver los dispositivos con sesión abierta y cerrar las demás sesiones si sospecha que alguien más entró a su cuenta. Guarde los códigos de recuperación en un lugar seguro."],
            '/coordinacion/panel' => ['Panel de coordinación', "Resumen del estado de su carrera y de la convocatoria vigente.\n\nLos accesos del panel llevan a las pantallas donde se resuelve cada pendiente."],
            '/coordinacion/revisiones' => ['Revisión de sílabos', "Lista de los sílabos de su carrera que los docentes ya enviaron. Biblioteca revisa primero la bibliografía; después el sílabo llega a coordinación.\n\nBusque por asignatura o docente y abra un sílabo para revisarlo, aprobarlo o pedir correcciones."],
            '/coordinacion/revisiones/:revision' => ['Detalle de la revisión', "Aquí revisa el contenido enviado por el docente, sección por sección.\n\nPuede registrar observaciones y solicitar correcciones, o aprobar el sílabo. Una revisión enviada no se modifica; si el docente corrige, se crea una nueva revisión y el historial permite compararlas.\n\nUn sílabo aprobado se conserva; para cambiarlo hay que reabrirlo, lo que crea otra revisión enlazada."],
            '/coordinacion/revisiones/:before/comparar/:after' => ['Comparar revisiones', "Muestra, campo por campo, lo que cambió entre dos revisiones del mismo sílabo.\n\nÚselo para verificar que el docente atendió sus observaciones antes de aprobar."],
            '/coordinacion/malla' => ['Malla curricular', "Vista de la malla de su carrera: ciclos, materias y relaciones de prerrequisito y correquisito.\n\nPulse «Editar malla» para modificarla. Cada materia tiene un menú para editarla, moverla, eliminarla o agregarle relaciones; el botón «Relaciones» lista todas en formato de tabla.\n\nSi hay una convocatoria abierta en su carrera, la malla está bloqueada; pause la convocatoria para corregirla."],
            '/coordinacion/proyecto-carrera' => ['Proyecto de carrera', "Datos de cada materia de la malla que heredan los sílabos: descripción, objetivos, resultados, habilidades blandas, perfil de egreso y bibliografía.\n\nPulse una materia para abrir su ficha. Con «Importar documentos» puede subir PDF o Excel de la carrera y la IA propone la ficha de cada materia; usted decide qué aplicar.\n\nLos cambios se aplican a los sílabos que se creen después. Con una convocatoria abierta la edición está bloqueada."],
            '/coordinacion/materias-asignaciones' => ['Materias y asignaciones', "Aquí programa las materias del período en curso, crea sus paralelos y elige el docente de cada uno.\n\nUse «Preparar período» para empezar desde la malla completa o copiar los paralelos y docentes del período anterior. Un paralelo sin docente queda pendiente de asignación.\n\nCambiar al docente de un paralelo que ya tiene sílabo lo borra y el nuevo docente empieza desde cero; el sistema se lo avisa antes de confirmar."],
            '/coordinacion/docentes' => ['Docentes de la carrera', "Cuentas docentes de su carrera y los paralelos que dictan en el período en curso.\n\nPuede crear un docente, importar varios desde Excel, corregir su nombre (y su correo, mientras la cuenta no esté activada) y retirarlo de la carrera. Cada docente recibe una invitación por correo para activar su cuenta."],
            '/coordinacion/revisiones/:revision/documentos' => ['Documentos aprobados', "Archivos de una revisión aprobada: el sílabo en Word y en PDF.\n\nGenere el documento, descárguelo y, si corresponde, fírmelo electrónicamente. La firma se hace en su equipo: su archivo de firma y su clave no se envían; solo se sube el PDF ya firmado."],
            '/coordinacion/notificaciones' => ['Notificaciones', "Aquí aparecen los avisos del sistema que le corresponden.\n\nPulse un aviso para ir a la pantalla relacionada; al abrirlo se marca como leído."],
            '/coordinacion/configuracion/perfil' => ['Perfil', 'Aquí consulta los datos de su cuenta. Su nombre y su correo institucional los administra Administración; si hay un error, pídale que lo corrija.'],
            '/coordinacion/configuracion/seguridad' => ['Seguridad', "Cambie su contraseña y administre la verificación en dos pasos. Para activarla o desactivarla se le pedirá su contraseña actual.\n\nTambién puede ver los dispositivos con sesión abierta y cerrar las demás sesiones si sospecha que alguien más entró a su cuenta. Guarde los códigos de recuperación en un lugar seguro."],
            '/docente/panel' => ['Panel docente', "Resumen de sus sílabos y de la convocatoria vigente.\n\nLos accesos del panel lo llevan al sílabo que necesita atención."],
            '/docente/mis-silabos' => ['Mis sílabos', "Un sílabo por cada paralelo que usted dicta en el período. Pulse uno para empezar o continuar su elaboración.\n\nEstados: sin iniciar, borrador, en revisión, corrección solicitada y aprobado. Mientras está en revisión no se puede editar; si Coordinación pide correcciones, vuelve a quedar editable."],
            '/docente/revisiones/:revision/documentos' => ['Documentos aprobados', "Cuando su sílabo está aprobado puede generar y descargar el documento en Word y en PDF.\n\nSi debe firmarlo, la firma se hace en su equipo: su archivo de firma y su clave no se envían; solo se sube el PDF ya firmado."],
            '/docente/notificaciones' => ['Notificaciones', "Aquí aparecen los avisos del sistema que le corresponden.\n\nPulse un aviso para ir a la pantalla relacionada; al abrirlo se marca como leído."],
            '/docente/configuracion/perfil' => ['Perfil', "Aquí consulta los datos de su cuenta. Su nombre y su correo institucional los administra Administración; si hay un error, pídale que lo corrija.\n\nSu formación y experiencia académica-investigativa se escribe una sola vez: todos sus sílabos la copian, y se actualizan los que aún no ha enviado."],
            '/docente/configuracion/seguridad' => ['Seguridad', "Cambie su contraseña y administre la verificación en dos pasos. Para activarla o desactivarla se le pedirá su contraseña actual.\n\nTambién puede ver los dispositivos con sesión abierta y cerrar las demás sesiones si sospecha que alguien más entró a su cuenta. Guarde los códigos de recuperación en un lugar seguro."],
            '/biblioteca/panel' => ['Panel de biblioteca', "Resumen de los sílabos enviados que esperan la revisión de su bibliografía.\n\nUse los accesos del panel para ir a la lista de revisiones."],
            '/biblioteca/revisiones' => ['Revisiones de biblioteca', "Aquí llegan, de todas las carreras, los sílabos enviados por los docentes para que verifique su bibliografía. Después de su verificación el sílabo pasa a coordinación.\n\nBusque por asignatura o filtre por estado y abra un sílabo para revisarlo."],
            '/biblioteca/revisiones/:revision' => ['Revisión de bibliografía', "Revise las fuentes bibliográficas del sílabo: que estén completas, sean consistentes y estén bien citadas.\n\nPuede dejar comentarios y marcar la bibliografía como verificada para que el sílabo continúe a coordinación."],
            '/biblioteca/notificaciones' => ['Notificaciones', "Aquí aparecen los avisos del sistema que le corresponden.\n\nPulse un aviso para ir a la pantalla relacionada; al abrirlo se marca como leído."],
            '/biblioteca/configuracion/perfil' => ['Perfil', 'Aquí consulta los datos de su cuenta. Su nombre y su correo institucional los administra Administración; si hay un error, pídale que lo corrija.'],
            '/biblioteca/configuracion/seguridad' => ['Seguridad', "Cambie su contraseña y administre la verificación en dos pasos. Para activarla o desactivarla se le pedirá su contraseña actual.\n\nTambién puede ver los dispositivos con sesión abierta y cerrar las demás sesiones si sospecha que alguien más entró a su cuenta. Guarde los códigos de recuperación en un lugar seguro."],
        ];
    }
};
