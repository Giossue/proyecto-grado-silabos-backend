# Modelo de dominio

Entidades reales del sistema, agrupadas por módulo. Entre paréntesis va la tabla. El
esquema completo está en `docs/generated/database-schema.md`; las reglas de estado, en
`syllabus-lifecycle.md`.

## Identidad y acceso (`Identity`)

- **Usuario** (`usuarios`): nombre en mayúsculas con tildes, correo único, activo,
  segundo factor, formación y experiencia (la usa la identificación del sílabo) y
  preferencias de accesibilidad.
- **Asignación de rol** (`asignaciones_rol`): usuario, rol, carrera y activa. Roles:
  `administrador`, `coordinador`, `docente`, `bibliotecario`. Coordinador y docente
  exigen carrera; administrador y bibliotecario no la tienen. No se repite una asignación
  activa con el mismo usuario, rol y carrera.
- **Invitación de activación** (`invitaciones_activacion`): enlace de un solo uso para
  verificar el correo y crear la contraseña. Vence y se invalida al emitir otra.

## Estructura académica (`Academic`)

- **Facultad** (`facultades`): nombre, campus (lista fija de cinco), logo (archivo) y
  activa.
- **Carrera** (`carreras`): facultad, código único, nombre, modalidad, número de ciclos
  (1 a 30) y activa. La malla es la propia carrera: no hay tabla de mallas ni versiones.
  Si una carrera tiene dos mallas a la vez (la vigente y la «no vigente habilitada para
  registro de títulos»), cada malla se registra como una carrera con su propio código y el
  mismo nombre. Cuando dos carreras se llaman igual, las pantallas, avisos, reportes y
  auditoría muestran «Nombre · código» (`Career::label()`); el sílabo lleva solo el nombre.
- **Asignatura** (`asignaturas`): carrera, código único en la carrera, nombre, ciclo,
  orden en el ciclo, unidad curricular (`basica`, `profesional`,
  `integracion_curricular`), modalidad propia opcional, horas de docencia, prácticas y
  autónomas, total de horas (lo calcula el servidor), créditos y activa.
- **Requisito de asignatura** (`requisitos_asignatura`): prerrequisito o correquisito
  entre dos asignaturas distintas.
- **Paralelo** (`paralelos`): asignatura, código (único en la asignatura) y jornada
  (`matutina`, `vespertina`, `nocturna`). No depende del período. Su creación exige
  seleccionar un docente de la carrera; el paralelo y su asignación se guardan en la
  misma transacción.
- **Asignación de paralelo** (`asignaciones_paralelo`): une un paralelo con la asignación
  de rol de un docente. Como máximo una activa por paralelo.
- **Período académico** (`periodos_academicos`): código único, fechas de inicio y fin y
  semanas lectivas (1 a 52). Su estado se calcula con la fecha actual.

## Plantilla (`Configuration`)

- **Plantilla del sílabo** (`plantilla_silabo`): hay una sola. `definicion_plantilla`
  guarda en JSON el papel, el estilo, las variables, los bloques y las secciones
  obligatorias (ADR-0011). `plantilla_modificada_en` cambia sola cuando cambia la
  definición.
- **Sección** (`secciones_plantilla`): clave, título, posición y orientación.
- **Campo** (`campos_plantilla`): sección, clave, título y posición.
- **Definición de campo** (`definiciones_campo`): quién lo llena (`carrera`
  o `institucional`) y su contenido inicial como documento. Un campo Carrera lo escribe
  Coordinación por materia (fila de `valores_campo` con `asignatura_id`) y el docente llena los
  espacios que le quedan.

Las filas retiradas se marcan como no vigentes mientras algún valor las use.

## Proyecto de carrera (`Academic`)

- **Proyecto de carrera de la asignatura**: filas de `valores_campo` con `asignatura_id` y sin
  `silabo_id`, una por campo de la plantilla que llena Coordinación. Solo la edita
  Coordinación. La fila de un campo Carrera guarda el texto de Coordinación y los espacios para el
  docente, con sus tipos de respuesta y opciones; las opciones propias de la materia sobre una
  respuesta de la plantilla llevan la marca `customOptions` (2026-11-16). De ahí parte el sílabo
  del docente.
- **Vista previa de importación**: objeto en memoria generado al leer el Excel.
  Coordinación revisa y selecciona materias y campos. Al aplicar se envía el mismo
  archivo y se vuelve a validar. Solo los valores confirmados se guardan en
  `valores_campo`. Cerrar o recargar descarta la vista previa; no hay tabla de cargas.

## Convocatoria y sílabo (`Syllabus`)

- **Convocatoria** (`convocatorias_universidad`): período (una por período), fecha de
  inicio, fecha de entrega (posterior al inicio), estado (`abierto`, `pausado`,
  `cerrado`) y fecha de pausa. Solo una puede estar abierta o pausada a la vez. Nace
  abierta. Hoy ninguna acción la pasa a `cerrado`.
- **Convocatoria de carrera** (`convocatorias_carreras`): carrera y convocatoria (una por
  carrera), estado (`preparacion`, `abierta`, `pausada`, `cerrada`). Se crea y se abre
  en la misma operación, así que en la práctica queda `abierta`.
- **Sílabo** (`silabos`): convocatoria de carrera, paralelo (uno por paralelo y
  convocatoria), estado, versión del borrador (control de concurrencia), fechas de
  inicio y último guardado, y `datos_academicos_silabo` (copia de malla, período y
  paralelo al crearse).
- **Valor de campo** (`valores_campo` con `silabo_id`): contenido de cada campo no fijo
  del sílabo. Al crear el sílabo se copia del proyecto de carrera o del contenido inicial, con su
  marca de editable.
- **Docente vigente del sílabo** (`docentes_vigentes_silabo`): vista que da el docente
  activo del paralelo.
- **Revisión** (`revisiones_silabo`): número correlativo, revisión anterior, clave de
  idempotencia, versión del borrador, `contenido_enviado` (copia completa: plantilla,
  contexto académico, logos, variables y valores), huella, quién y cuándo envió. Si se
  devolvió, guarda comentario, etapa (`coordinacion` o `biblioteca`), quién y cuándo;
  los cuatro datos van juntos o ninguno.
- **Envío a biblioteca** (`envios_biblioteca`): uno por revisión enviada; la pone en la
  bandeja de Biblioteca. Guarda quién verificó la bibliografía y cuándo
  (`verificado_por`, `verificado_en`, se escriben una sola vez). Si Coordinación devolvió y
  la bibliografía no cambió, el reenvío copia la verificación anterior y la marca
  `verificacion_heredada`; esa copia no cuenta como trabajo de Biblioteca.
- **Revisión de coordinación** (`revisiones_coordinacion`): una por revisión; quién y
  cuándo aprobó Coordinación. Se registra junto con la aprobación.
- **Aprobación** (`aprobaciones`): una por revisión; la da Coordinación tras la
  verificación de Biblioteca; quién y cuándo, clave de idempotencia y huella.

## Documentos (`Documents`)

- **Archivo** (`archivos`): objeto privado con disco, ruta, nombre, tipo, tamaño, huella
  y categoría (logo de facultad, exportación).
- **Exportación** (`exportaciones_silabo`): revisión, archivo PDF, clave de
  idempotencia y estado (`pendiente`, `en_ejecucion`, `completado`, `fallido`).
  Completada exige el PDF. No guarda Word.
- **Firma** (`firmas_silabo`): sílabo, revisión sobre la que se decidió, rol con que firma
  (`docente`, `coordinacion`, `biblioteca`; una de cada una por sílabo), usuario,
  declaración firmada, firma CMS en base64 y datos del certificado (titular, número,
  entidad emisora y vigencia).

## IA (`AiAssistance`)

- **Mensaje y propuesta del asistente de la plantilla** (`mensajes_ia_plantilla`,
  `propuestas_ia_plantilla`): solo Administración. El chat del docente y su tabla
  `mensajes_ia` se retiraron el 2026-11-05.

## Operación (`Operations`)

- **Notificación** (`notificaciones`): usuario, clave única (evita duplicados), tipo,
  título, mensaje, recurso, período académico y fecha de lectura.
- **Evento de auditoría** (`eventos_auditoria`): acción, recurso, resultado (`exito` o
  `fallido`), detalles y una copia del nombre, rol y carrera del actor.
- **Ayuda de pantalla**: texto fijo por ruta de pantalla, en `ScreenHelpCatalog`; no se
  guarda en la base ni se edita desde el sistema.

## Invariantes

1. Toda acción protegida exige usuario activo y permiso sobre el registro.
2. Una carrera tiene como máximo un coordinador activo.
3. Un paralelo tiene como máximo un docente activo.
4. Hay una convocatoria por período y solo una abierta o pausada a la vez.
5. Hay un sílabo por paralelo en cada convocatoria de carrera.
6. Todo envío crea una revisión nueva; nunca modifica la anterior.
7. Una revisión enviada no se edita. Solo desaparece si se elimina o se rehace el
   sílabo completo, o si vuelve a borrador por un cambio de la base institucional.
8. Envío a biblioteca, verificación de Biblioteca, revisión de coordinación y aprobación
   se registran una sola vez por revisión, en ese orden.
9. El PDF se genera desde la copia guardada en la revisión.
10. Cada persona firma una vez por sílabo, al decidir: docente al primer envío,
    Biblioteca al verificar y Coordinación al aprobar.
11. La IA no cambia estados ni escribe en el sílabo.
12. Perder la cola o el servicio de IA no daña el sílabo.
13. El total de horas de una asignatura es la suma de sus componentes.
