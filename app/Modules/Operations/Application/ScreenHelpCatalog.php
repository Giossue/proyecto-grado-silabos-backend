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
            'content' => 'Aquí queda anotado quién hizo cada cambio importante y cuándo.

Solo se puede consultar.',
        ],
        '/admin/configuracion/perfil' => [
            'title' => 'Perfil',
            'content' => 'Aquí cambia su nombre. El correo solo se puede cambiar antes de activar la cuenta.',
        ],
        '/admin/configuracion/seguridad' => [
            'title' => 'Seguridad',
            'content' => 'Aquí cambia su contraseña. Si no la recuerda, puede pedir un enlace por correo para crear otra.

También puede activar la doble verificación: al entrar le pedirá un código del celular. Guarde los códigos de respaldo en un lugar seguro; sirven si pierde el celular.

Abajo ve los dispositivos donde su cuenta está abierta. Si no reconoce alguno, use «Cerrar otras sesiones» (pide su contraseña) y cambie la contraseña.',
        ],
        '/admin/configuracion/sistema' => [
            'title' => 'Sistema',
            'content' => 'Aquí se configuran los avisos por correo, el asistente y la conexión con la biblioteca.

Abra cada parte para cambiarla. Algunas piden su contraseña antes de guardar.',
        ],
        '/admin/convocatorias' => [
            'title' => 'Convocatorias',
            'content' => 'Aquí abre y controla el tiempo en que los docentes hacen sus sílabos. Solo puede haber una convocatoria en curso a la vez.

Al pulsar una fila se abren sus opciones: pausar, reanudar, prorrogar el plazo, finalizar o eliminar la convocatoria; un período sin convocatoria se puede abrir o eliminar. Cada coordinación inicia después la convocatoria de su carrera.

El plazo se puede prorrogar 2 veces como máximo, y nunca después del último día de clases del período.

Finalizar le muestra antes cuántos sílabos quedan sin aprobar, y esos quedan solo para consulta. Si no la finaliza, el sistema lo hace solo al terminar el último día de entrega; 3 días antes avisa a docentes y coordinación con sílabos sin aprobar. Una convocatoria finalizada se vuelve a abrir con «Prorrogar plazo», con los mismos límites, si no hay otra en curso y la plantilla está lista para usar. Mientras esté finalizada, los cambios de malla, proyecto de carrera, paralelos, docentes o coordinador no tocan sus sílabos; se aplican al reabrirlase.

Eliminar solo se puede con la convocatoria abierta o pausada. Borra todos sus sílabos, incluso los aprobados; antes le muestra cuántos se pierden y le pide un mensaje que llega por aviso y correo a docentes, coordinación y biblioteca.

Mientras la convocatoria esté abierta, la plantilla del sílabo no se puede modificar; para corregirla, pause el proceso.

Arriba están los indicadores de la convocatoria vigente. El botón rojo de check, abajo a la derecha, abre «Antes de empezar»: los pasos previos (facultades, carreras, cuentas, coordinadores y plantilla); al pulsar uno le muestra en el menú dónde se hace. El de la plantilla queda pendiente mientras no se pueda usar y dice qué le falta. «Nueva convocatoria» se habilita cuando están todos y no hay otra abierta o pausada.',
        ],
        '/admin/estructura-academica' => [
            'title' => 'Facultades y carreras',
            'content' => 'Aquí están las facultades y las carreras de la universidad.

Pulse una fila para cambiar sus datos. Nada se borra: lo que ya no se usa se desactiva y se puede volver a activar. Las carreras también se pueden subir con un Excel.

Si hay una convocatoria abierta, un aviso arriba le explica qué pasa con los sílabos si cambia algo.',
        ],
        '/admin/estructura-academica/:section' => [
            'title' => 'Facultades y carreras',
            'content' => 'Aquí están las facultades y las carreras de la universidad.

Pulse una fila para cambiar sus datos. Nada se borra: lo que ya no se usa se desactiva y se puede volver a activar. Las carreras también se pueden subir con un Excel.

Si hay una convocatoria abierta, un aviso arriba le explica qué pasa con los sílabos si cambia algo.',
        ],
        '/admin/notificaciones' => [
            'title' => 'Notificaciones',
            'content' => 'Aquí están sus avisos del período actual.

Pulse «Abrir» para ir a donde corresponde, o «Marcar leída» para quitarlo de los nuevos.',
        ],
        '/admin/plantilla' => [
            'title' => 'Plantilla del sílabo',
            'content' => 'Es el formato del sílabo, el mismo para todas las carreras.

«Editar» abre el editor y pide escribir «editar plantilla». Ahí escribe directo en la hoja, elige quién llena cada campo (docente, carrera o texto fijo) y pulsa «Guardar». El logo de la universidad y «Editar JSON» se guardan al instante, sin pasar por «Guardar».

Con una convocatoria abierta la plantilla está bloqueada. Para cambiarla, pause la convocatoria. Al reanudar se le pedirá confirmación: los sílabos de este período se adaptan a la plantilla nueva, porque el formato es único para toda la universidad. Se conserva lo escrito en los campos que siguen encajando; los enviados o aprobados vuelven a borrador para enviarse y firmarse de nuevo.

Los sílabos de períodos anteriores no se tocan.',
        ],
        '/admin/usuarios' => [
            'title' => 'Usuarios y roles',
            'content' => 'Aquí crea las cuentas de coordinación, docentes y biblioteca, una por una o con un Excel. Cada persona recibe un correo para entrar por primera vez.

Pulse una persona para ver sus datos, cambiar sus roles o quitarle el acceso. Una persona puede tener más de un rol.',
        ],
        '/biblioteca/configuracion/notificaciones' => [
            'title' => 'Avisos por correo',
            'content' => 'Elija qué avisos le llegan también a su correo. En el sistema los verá siempre.',
        ],
        '/biblioteca/configuracion/perfil' => [
            'title' => 'Perfil',
            'content' => 'Aquí ve los datos de su cuenta. Si su nombre o correo están mal, pida el cambio a Administración.',
        ],
        '/biblioteca/configuracion/seguridad' => [
            'title' => 'Seguridad',
            'content' => 'Aquí cambia su contraseña. Si no la recuerda, puede pedir un enlace por correo para crear otra.

También puede activar la doble verificación: al entrar le pedirá un código del celular. Guarde los códigos de respaldo en un lugar seguro; sirven si pierde el celular.

Abajo ve los dispositivos donde su cuenta está abierta. Si no reconoce alguno, use «Cerrar otras sesiones» (pide su contraseña) y cambie la contraseña.',
        ],
        '/biblioteca/historial' => [
            'title' => 'Historial de biblioteca',
            'content' => 'Aquí están los sílabos de períodos que ya terminaron. Solo se pueden ver.

Filtre por período o facultad. «Solo los míos» muestra los que usted atendió.',
        ],
        '/biblioteca/notificaciones' => [
            'title' => 'Notificaciones',
            'content' => 'Aquí están sus avisos del período actual.

Pulse «Abrir» para ir a donde corresponde, o «Marcar leída» para quitarlo de los nuevos.',
        ],
        '/biblioteca/revisiones' => [
            'title' => 'Revisiones de biblioteca',
            'content' => 'Aquí llegan, de todas las carreras, los sílabos que Coordinación ya firmó, para que revise sus libros.

Pulse uno para revisarlo. Si los libros están bien, lo firma y queda aprobado. Si algo falla, lo devuelve al docente.

Puede filtrar por facultad si se reparten el trabajo; el sistema recuerda su elección. Lo de convocatorias anteriores está en «Historial».',
        ],
        '/biblioteca/revisiones/:revision' => [
            'title' => 'Revisión de bibliografía',
            'content' => 'Revise que los libros del sílabo estén en la biblioteca y que sus datos estén completos y bien escritos.

Si todo está bien, pulse «Firmar y aprobar» y use su archivo de firma electrónica. Si hay un problema, pulse «Devolver al docente» y marque qué debe corregir. Cuando el docente lo corrija, vuelve directo a usted.',
        ],
        '/coordinacion/configuracion/notificaciones' => [
            'title' => 'Avisos por correo',
            'content' => 'Elija qué avisos le llegan también a su correo. En el sistema los verá siempre.',
        ],
        '/coordinacion/configuracion/perfil' => [
            'title' => 'Perfil',
            'content' => 'Aquí ve los datos de su cuenta. Si su nombre o correo están mal, pida el cambio a Administración.',
        ],
        '/coordinacion/configuracion/seguridad' => [
            'title' => 'Seguridad',
            'content' => 'Aquí cambia su contraseña. Si no la recuerda, puede pedir un enlace por correo para crear otra.

También puede activar la doble verificación: al entrar le pedirá un código del celular. Guarde los códigos de respaldo en un lugar seguro; sirven si pierde el celular.

Abajo ve los dispositivos donde su cuenta está abierta. Si no reconoce alguno, use «Cerrar otras sesiones» (pide su contraseña) y cambie la contraseña.',
        ],
        '/coordinacion/docentes' => [
            'title' => 'Docentes de la carrera',
            'content' => 'Aquí están los docentes de su carrera.

Cree uno o suba varios con un Excel; cada uno recibe un correo para entrar. Si la persona ya tiene cuenta, solo se suma a su carrera; si no, recibe el enlace de registro. Pulse un docente para ver sus datos, reenviarle la invitación si venció sin aceptarla o quitarlo de la carrera; su cuenta (contraseña y datos) la gestiona Administración. Mientras haya una convocatoria en curso no se puede quitar a nadie.',
        ],
        '/coordinacion/historial' => [
            'title' => 'Historial',
            'content' => 'Aquí están los sílabos aprobados de su carrera en períodos que ya terminaron, del período más reciente al más antiguo. Solo se pueden ver.

Busque por materia o código, o elija un período. «Elaborado por» es el docente que envió el sílabo, aunque ya no dicte ese paralelo ni esté en la carrera. Pulse una fila para ver el sílabo o el ícono «Ver PDF» para abrir el documento firmado.',
        ],
        '/coordinacion/malla' => [
            'title' => 'Malla curricular',
            'content' => 'Aquí ve la malla de su carrera: sus ciclos, sus materias y qué materia es requisito de otra.

Pulse «Editar malla» para cambiarla. «Relaciones» muestra los requisitos en una lista.

Si hay una convocatoria abierta, la malla no se puede cambiar; primero páusela.',
        ],
        '/coordinacion/materias-asignaciones' => [
            'title' => 'Materias y asignaciones',
            'content' => 'Aquí prepara las materias del período: sus paralelos, su jornada y el docente de cada uno.

Marque una o varias materias para hacer cambios a la vez, o pulse una para cambiar sus paralelos.

Si cambia el docente de un paralelo, su sílabo empieza de cero. El sistema le pregunta antes.',
        ],
        '/coordinacion/notificaciones' => [
            'title' => 'Notificaciones',
            'content' => 'Aquí están sus avisos del período actual.

Pulse «Abrir» para ir a donde corresponde, o «Marcar leída» para quitarlo de los nuevos.',
        ],
        '/coordinacion/proyecto-carrera' => [
            'title' => 'Proyecto de carrera',
            'content' => 'Aquí llena lo que le toca a la carrera en el sílabo de cada materia. El docente lo recibe ya escrito.

Pulse una materia para llenarla, o llene varias a la vez con «Importar desde Excel».

En «Documentos de apoyo» suba archivos para que sus docentes conozcan mejor sus materias.

Si hay una convocatoria abierta, no se puede cambiar; primero páusela.',
        ],
        '/coordinacion/revisiones' => [
            'title' => 'Revisión de sílabos',
            'content' => 'Arriba ve el aviso con el plazo de entrega. Desde aquí abre, pausa o reanuda la convocatoria de su carrera.

El botón rojo de check abre «Antes de empezar»: lo que falta preparar antes de abrir la convocatoria.

«Cola de revisión» tiene los sílabos que le enviaron. Pulse uno para revisarlo: puede pedir correcciones o firmarlo y enviarlo a biblioteca. «Seguimiento» muestra cómo va cada sílabo.',
        ],
        '/coordinacion/revisiones/:revision' => [
            'title' => 'Detalle de la revisión',
            'content' => 'Aquí lee el sílabo que envió el docente.

Si falta algo, pulse «Solicitar corrección» y escriba qué cambiar. Si está bien, pulse «Firmar y enviar a biblioteca» y use su archivo de firma electrónica.

Con «Historial» y «Comparar» ve qué cambió entre versiones.',
        ],
        '/coordinacion/revisiones/:revision/documentos' => [
            'title' => 'Documentos aprobados',
            'content' => 'Aquí ve o descarga el PDF del sílabo aprobado, con las tres firmas.

Si el PDF todavía se está preparando, espere un momento. Si falla, pulse «Reintentar».',
        ],
        '/coordinacion/revisiones/:revision/historial' => [
            'title' => 'Historial de versiones',
            'content' => 'Aquí ve cada versión que envió el docente y los comentarios de cada una.

Pulse una versión para verla. «Resaltar cambios» marca lo que cambió.',
        ],
        '/docente/configuracion/notificaciones' => [
            'title' => 'Avisos por correo',
            'content' => 'Elija qué avisos le llegan también a su correo. En el sistema los verá siempre.',
        ],
        '/docente/configuracion/perfil' => [
            'title' => 'Perfil',
            'content' => 'Aquí ve los datos de su cuenta. Si su nombre o correo están mal, pida el cambio a Administración.

Su formación y experiencia se escribe una sola vez y pasa a todos sus sílabos.',
        ],
        '/docente/configuracion/seguridad' => [
            'title' => 'Seguridad',
            'content' => 'Aquí cambia su contraseña. Si no la recuerda, puede pedir un enlace por correo para crear otra.

También puede activar la doble verificación: al entrar le pedirá un código del celular. Guarde los códigos de respaldo en un lugar seguro; sirven si pierde el celular.

Abajo ve los dispositivos donde su cuenta está abierta. Si no reconoce alguno, use «Cerrar otras sesiones» (pide su contraseña) y cambie la contraseña.',
        ],
        '/docente/documentos-apoyo' => [
            'title' => 'Documentos de apoyo',
            'content' => 'Archivos que su coordinación le dejó para conocer mejor sus materias. Pulse uno para verlo.',
        ],
        '/docente/historial' => [
            'title' => 'Historial',
            'content' => 'Aquí están sus sílabos de períodos anteriores. Pulse una carrera para verlos.',
        ],
        '/docente/historial/:career' => [
            'title' => 'Historial',
            'content' => 'Sus sílabos de períodos anteriores en esta carrera. Pulse uno para verlo.',
        ],
        '/docente/mis-silabos' => [
            'title' => 'Mis sílabos',
            'content' => 'Arriba ve el aviso con el plazo de la convocatoria.

Debajo tiene un sílabo por cada materia que dicta. Pulse uno para empezar o seguir.

Cuando lo envía, no se puede cambiar mientras lo revisan. Si le piden correcciones, puede volver a editarlo. Sus sílabos de períodos anteriores están en «Historial». Si Coordinación compartió material de la carrera, el ícono de carpeta «Documentos de apoyo» lo muestra; cada documento se abre en una ventana aparte.',
        ],
        '/docente/mis-silabos/:syllabus' => [
            'title' => 'Su sílabo',
            'content' => 'Aquí llena su sílabo. Los campos con asterisco son suyos; lo demás ya viene escrito. Todo se guarda solo mientras escribe.

Cuando termine, pulse «Enviar». La primera vez lo firma con su archivo de firma electrónica.',
        ],
        '/docente/notificaciones' => [
            'title' => 'Notificaciones',
            'content' => 'Aquí están sus avisos del período actual.

Pulse «Abrir» para ir a donde corresponde, o «Marcar leída» para quitarlo de los nuevos.',
        ],
        '/docente/revisiones/:revision/documentos' => [
            'title' => 'Documentos aprobados',
            'content' => 'Aquí ve o descarga el PDF del sílabo aprobado, con las tres firmas.

Si el PDF todavía se está preparando, espere un momento. Si falla, pulse «Reintentar».',
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
