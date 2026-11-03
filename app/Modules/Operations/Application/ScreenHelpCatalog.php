<?php

namespace App\Modules\Operations\Application;

/**
 * Textos del botón «Ayuda de esta pantalla». Están en el código: no se editan desde el sistema.
 * La clave es la ruta de la pantalla en el frontend, con sus parámetros (`:revision`).
 */
final class ScreenHelpCatalog
{
    /** @var array<string, array{title: string, content: string}> */
    public const HELP = [
        '/admin/auditoria' => [
            'title' => 'Auditoría',
            'content' => 'Registro de las acciones importantes que se hacen en el sistema: quién las hizo, cuándo, con qué rol y con qué resultado.

Es solo de consulta.',
        ],
        '/admin/configuracion/perfil' => [
            'title' => 'Perfil',
            'content' => 'Aquí puede actualizar su nombre y su correo electrónico.',
        ],
        '/admin/configuracion/seguridad' => [
            'title' => 'Seguridad',
            'content' => 'Cambie su contraseña y administre la verificación en dos pasos. Para activarla o desactivarla se le pedirá su contraseña actual.

Guarde los códigos de recuperación en un lugar seguro: le permiten entrar si pierde el acceso a su aplicación de autenticación.',
        ],
        '/admin/convocatorias' => [
            'title' => 'Convocatorias',
            'content' => 'Cada fila es un período lectivo y su convocatoria institucional: las fechas de clases y las fechas en que los docentes elaboran y entregan sus sílabos. Solo puede haber una convocatoria abierta o pausada a la vez.

Desde el menú de la fila puede abrir, pausar, reanudar, cerrar o eliminar la convocatoria, y extender el plazo de entrega. Cada coordinación inicia después la convocatoria de su carrera.

Eliminar solo se puede con la convocatoria abierta o pausada. Borra todos sus sílabos, incluso los aprobados; antes le muestra cuántos se pierden y le pide un mensaje que llega por aviso y correo a docentes, coordinación y biblioteca.

Mientras la convocatoria esté abierta, la plantilla del sílabo no se puede modificar; para corregirla, pause el proceso.

Arriba están los indicadores de la convocatoria vigente. El botón rojo de check, abajo a la derecha, abre «Antes de empezar»: los pasos previos (facultades, carreras, cuentas, coordinadores y plantilla); al pulsar uno le muestra en el menú dónde se hace. «Nueva convocatoria» se habilita cuando están todos.',
        ],
        '/admin/estructura-academica' => [
            'title' => 'Facultades y carreras',
            'content' => 'Administre el catálogo académico: facultades (con su logo en PNG), carreras, coordinadores y períodos académicos. Use las pestañas para cambiar de catálogo.

Puede crear, editar y activar o desactivar cada registro; un registro con historial no se elimina, solo se desactiva. Las carreras también se pueden importar desde Excel, y desde una carrera puede reemplazar a su coordinador.

Mientras haya una convocatoria en curso, algunos cambios quedan bloqueados; el aviso de la pantalla le dice qué hacer.',
        ],
        '/admin/estructura-academica/:section' => [
            'title' => 'Facultades y carreras',
            'content' => 'Administre el catálogo académico: facultades (con su logo en PNG), carreras, coordinadores y períodos académicos. Use las pestañas para cambiar de catálogo.

Puede crear, editar y activar o desactivar cada registro; un registro con historial no se elimina, solo se desactiva. Las carreras también se pueden importar desde Excel, y desde una carrera puede reemplazar a su coordinador.

Mientras haya una convocatoria en curso, algunos cambios quedan bloqueados; el aviso de la pantalla le dice qué hacer.',
        ],
        '/admin/notificaciones' => [
            'title' => 'Notificaciones',
            'content' => 'Aquí aparecen los avisos del período en curso que le corresponden. Cuando el período termina, sus avisos ya no se muestran.

Pulse un aviso para ir a la pantalla relacionada; al abrirlo se marca como leído.',
        ],
        '/admin/plantilla' => [
            'title' => 'Plantilla del sílabo',
            'content' => 'Es el formato único del sílabo: sus secciones, sus campos y el orden en que aparecen. Aquí también se configura el logo institucional.

En «Editar → Listas» se fija cuántas unidades y cuántas semanas por unidad puede tener el docente: mínimo, máximo y con cuántas empieza.

Con una convocatoria abierta la plantilla está bloqueada. Para cambiarla, pause la convocatoria. Al reanudar se le pedirá confirmación: todos los sílabos de este período se borran y empiezan desde cero, incluso los aprobados, porque el formato es único para toda la universidad.

Los sílabos de períodos anteriores no se tocan. Si la plantilla está vacía, puede cargar el formato oficial de la UEB.',
        ],
        '/admin/usuarios' => [
            'title' => 'Usuarios y roles',
            'content' => 'Cree las cuentas de coordinación, docencia y biblioteca, una por una o importándolas desde Excel. Cada persona recibe un correo de invitación para verificar su correo y definir su contraseña.

Coordinación y docencia se asignan a una carrera; biblioteca trabaja con toda la universidad. Una persona puede tener más de un rol: el «+1» junto al rol indica que tiene otros.

Pulse una fila para ver el detalle de la cuenta y use «Editar» para corregir sus datos, quitar un rol o agregar otro. Desde el menú de la fila puede desactivar o reactivar la cuenta, y reenviar la invitación si sigue pendiente. Al desactivar una cuenta, su sesión se cierra.',
        ],
        '/biblioteca/configuracion/perfil' => [
            'title' => 'Perfil',
            'content' => 'Aquí consulta los datos de su cuenta. Su nombre y su correo institucional los administra Administración; si hay un error, pídale que lo corrija.',
        ],
        '/biblioteca/configuracion/seguridad' => [
            'title' => 'Seguridad',
            'content' => 'Cambie su contraseña y administre la verificación en dos pasos. Para activarla o desactivarla se le pedirá su contraseña actual.

Guarde los códigos de recuperación en un lugar seguro: le permiten entrar si pierde el acceso a su aplicación de autenticación.',
        ],
        '/biblioteca/historial' => [
            'title' => 'Historial de biblioteca',
            'content' => 'Los sílabos de períodos terminados, en solo lectura. Aquí llegan solos cuando termina el período académico.

Filtre por período, facultad o estado. «Solo los míos» muestra los que usted firmó o devolvió.',
        ],
        '/biblioteca/notificaciones' => [
            'title' => 'Notificaciones',
            'content' => 'Aquí aparecen los avisos del período en curso que le corresponden. Cuando el período termina, sus avisos ya no se muestran.

Pulse un aviso para ir a la pantalla relacionada; al abrirlo se marca como leído.',
        ],
        '/biblioteca/revisiones' => [
            'title' => 'Revisiones de biblioteca',
            'content' => 'Arriba ve cuántos sílabos están en revisión y cuántos ya atendió: aprobados, con corrección solicitada al docente o reemplazados por una revisión nueva (anteriores).

Debajo llegan, de todas las carreras, los sílabos del período en curso que Coordinación ya firmó, para que revise su bibliografía. Cuando usted firma y aprueba, el sílabo queda aprobado.

Filtre por facultad si se reparten el trabajo: el sistema recuerda la que eligió. Los períodos terminados están en «Historial».',
        ],
        '/biblioteca/revisiones/:revision' => [
            'title' => 'Revisión de bibliografía',
            'content' => 'Revise los libros que propuso el docente: que estén en biblioteca, completos y bien citados.

Si todo está bien, pulse «Firmar y aprobar» y firme con su archivo .p12: el sílabo queda aprobado y se genera el PDF. Si hay un problema, devuélvalo al docente marcando los motivos; cuando lo corrija y reenvíe, vuelve a esta bandeja sin pasar de nuevo por Coordinación.',
        ],
        '/coordinacion/configuracion/perfil' => [
            'title' => 'Perfil',
            'content' => 'Aquí consulta los datos de su cuenta. Su nombre y su correo institucional los administra Administración; si hay un error, pídale que lo corrija.',
        ],
        '/coordinacion/configuracion/seguridad' => [
            'title' => 'Seguridad',
            'content' => 'Cambie su contraseña y administre la verificación en dos pasos. Para activarla o desactivarla se le pedirá su contraseña actual.

Guarde los códigos de recuperación en un lugar seguro: le permiten entrar si pierde el acceso a su aplicación de autenticación.',
        ],
        '/coordinacion/docentes' => [
            'title' => 'Docentes de la carrera',
            'content' => 'Cuentas docentes de su carrera y los paralelos que dictan en el período en curso.

Puede crear un docente, importar varios desde Excel, corregir su nombre (y su correo, mientras la cuenta no esté activada) y retirarlo de la carrera. Cada docente recibe una invitación por correo para activar su cuenta.',
        ],
        '/coordinacion/malla' => [
            'title' => 'Malla curricular',
            'content' => 'Vista de la malla de su carrera: ciclos, materias y relaciones de prerrequisito y correquisito.

Pulse «Editar malla» para modificarla. Cada materia tiene un menú para editarla, moverla, eliminarla o agregarle relaciones; el botón «Relaciones» lista todas en formato de tabla.

Si hay una convocatoria abierta en su carrera, la malla está bloqueada; pause la convocatoria para corregirla.',
        ],
        '/coordinacion/materias-asignaciones' => [
            'title' => 'Materias y asignaciones',
            'content' => 'Aquí programa las materias del período en curso, crea sus paralelos y elige el docente de cada uno.

Seleccione una o varias materias para agregarles paralelos. Un paralelo sin docente queda pendiente de asignación.

Si asigna un docente nuevo a un paralelo, su sílabo empieza desde cero. Cambiar el código o la jornada de un paralelo con el sílabo enviado o aprobado también lo elimina; el sistema le pide confirmación antes.',
        ],
        '/coordinacion/notificaciones' => [
            'title' => 'Notificaciones',
            'content' => 'Aquí aparecen los avisos del período en curso que le corresponden. Cuando el período termina, sus avisos ya no se muestran.

Pulse un aviso para ir a la pantalla relacionada; al abrirlo se marca como leído.',
        ],
        '/coordinacion/proyecto-carrera' => [
            'title' => 'Proyecto de carrera',
            'content' => 'El proyecto de carrera contiene los campos que Administración asignó a Coordinación en la plantilla del sílabo. Pulse una materia para editar sus datos y elegir qué campos puede modificar el docente.

Con «Importar desde Excel», descargue la plantilla de su carrera. Complete las celdas de contenido sin cambiar hojas, encabezados ni materias. Cargue el Excel, revise la vista previa y seleccione qué materias y campos aplicar. Las celdas vacías conservan los valores actuales. Esta carga no necesita IA ni OCR.

En la pestaña «Documentos de apoyo» suba PDF, Word o Excel de hasta 20 MB para sus docentes: proyecto de carrera, TIC y TAC, habilidades blandas u otros. Ellos los ven en una ventana aparte. Al eliminar uno, se borra del servidor.

Con una convocatoria abierta la edición está bloqueada. Si hay sílabos en curso, cambiar un dato exige pausar y confirmar su regeneración; los procesos cerrados no se modifican.',
        ],
        '/coordinacion/revisiones' => [
            'title' => 'Revisión de sílabos',
            'content' => 'Arriba ve el aviso con el plazo de entrega. Desde aquí abre, pausa o reanuda la convocatoria de su carrera.

El botón rojo de check, abajo a la derecha, abre «Antes de empezar»: los pasos previos (malla, materias del período, paralelos y docentes); al pulsar uno le muestra en el menú dónde se hace. «Abrir convocatoria» se habilita cuando todo está listo; si no, al pasar el cursor dice qué falta.

«Cola de revisión» lista los sílabos que los docentes ya enviaron. Coordinación revisa primero el contenido; cuando lo aprueba, el sílabo pasa a Biblioteca para revisar la bibliografía. Abra uno para aprobarlo o pedir correcciones.

«Seguimiento» muestra todos los sílabos de la convocatoria con su estado.',
        ],
        '/coordinacion/revisiones/:revision' => [
            'title' => 'Detalle de la revisión',
            'content' => 'Aquí revisa el contenido enviado por el docente, sección por sección.

Puede solicitar una corrección con un comentario para el docente o pulsar «Firmar y enviar a biblioteca» y firmar con su archivo .p12. Una revisión enviada no se modifica: si el docente corrige, se crea una nueva revisión y el historial permite ver y comparar las versiones.

Un sílabo aprobado no se modifica. Si tiene un error, puede reiniciarlo con un motivo y el docente empieza de cero.',
        ],
        '/coordinacion/revisiones/:revision/documentos' => [
            'title' => 'Documentos aprobados',
            'content' => 'El PDF del sílabo aprobado, con las firmas del docente, Coordinación y Biblioteca. Véalo o descárguelo.',
        ],
        '/docente/configuracion/perfil' => [
            'title' => 'Perfil',
            'content' => 'Aquí consulta los datos de su cuenta. Su nombre y su correo institucional los administra Administración; si hay un error, pídale que lo corrija.

Su formación y experiencia académica-investigativa se escribe una sola vez: todos sus sílabos la copian, y se actualizan los que aún no ha enviado.',
        ],
        '/docente/configuracion/seguridad' => [
            'title' => 'Seguridad',
            'content' => 'Cambie su contraseña y administre la verificación en dos pasos. Para activarla o desactivarla se le pedirá su contraseña actual.

Guarde los códigos de recuperación en un lugar seguro: le permiten entrar si pierde el acceso a su aplicación de autenticación.',
        ],
        '/docente/documentos-apoyo' => [
            'title' => 'Documentos de apoyo',
            'content' => 'Material que Coordinación subió para conocer mejor sus asignaturas. Pulse uno para verlo en una ventana aparte.',
        ],
        '/docente/mis-silabos' => [
            'title' => 'Mis sílabos',
            'content' => 'Arriba ve el aviso con el plazo de la convocatoria.

Debajo, un sílabo por cada paralelo que usted dicta en el período. Pulse uno para empezar o continuar su elaboración.

Estados: sin iniciar, borrador, en revisión, corrección solicitada y aprobado. Al primer envío lo firma con su archivo .p12; los reenvíos no se vuelven a firmar. Mientras está en revisión no se puede editar; si Coordinación o Biblioteca piden correcciones, vuelve a quedar editable.

Los sílabos de períodos anteriores están en «Historial».',
        ],
        '/docente/notificaciones' => [
            'title' => 'Notificaciones',
            'content' => 'Aquí aparecen los avisos del período en curso que le corresponden. Cuando el período termina, sus avisos ya no se muestran.

Pulse un aviso para ir a la pantalla relacionada; al abrirlo se marca como leído.',
        ],
        '/docente/revisiones/:revision/documentos' => [
            'title' => 'Documentos aprobados',
            'content' => 'Cuando su sílabo está aprobado puede ver y descargar el PDF con las tres firmas.',
        ],
    ];

    /** @return array{screen: string, title: string, content: string}|null */
    public function forScreen(string $screen): ?array
    {
        $help = self::HELP[$screen] ?? null;

        return $help === null ? null : ['screen' => $screen, ...$help];
    }

    /** @return list<array{screen: string, title: string, content: string}> */
    public function underPrefix(string $prefix): array
    {
        $items = [];
        foreach (self::HELP as $screen => $help) {
            if ($screen === $prefix || str_starts_with($screen, $prefix.'/')) {
                $items[] = ['screen' => $screen, ...$help];
            }
        }

        return $items;
    }
}
