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
- **Definición de campo** (`definiciones_campo`): quién lo llena (`docente`,
  `coordinacion`, `institucional`) y su contenido inicial como documento.

Las filas retiradas se marcan como no vigentes mientras algún valor las use.

## Proyecto de carrera (`Academic`)

- **Ficha de la asignatura**: filas de `valores_campo` con `asignatura_id` y sin
  `silabo_id`, una por campo de la plantilla que llena Coordinación.
  `editable_docente_valor_campo` indica si el docente puede editar ese campo en su
  sílabo.
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
  del sílabo. Al crear el sílabo se copia de la ficha o del contenido inicial, con su
  marca de editable.
- **Docente vigente del sílabo** (`docentes_vigentes_silabo`): vista que da el docente
  activo del paralelo.
- **Revisión** (`revisiones_silabo`): número correlativo, revisión anterior, clave de
  idempotencia, versión del borrador, `contenido_enviado` (copia completa: plantilla,
  contexto académico, logos, variables y valores), huella, quién y cuándo envió. Si se
  devolvió, guarda comentario, etapa (`coordinacion` o `biblioteca`), quién y cuándo;
  los cuatro datos van juntos o ninguno.
- **Revisión de coordinación** (`revisiones_coordinacion`): una por revisión; quién y
  cuándo aprobó la etapa de coordinación.
- **Envío a biblioteca** (`envios_biblioteca`): una por revisión; la pone en la bandeja
  de Biblioteca.
- **Aprobación** (`aprobaciones`): una por revisión; quién y cuándo, clave de
  idempotencia y huella.

## Documentos (`Documents`)

- **Archivo** (`archivos`): objeto privado con disco, ruta, nombre, tipo, tamaño, huella
  y categoría (logo de facultad, exportación, PDF firmado).
- **Exportación** (`exportaciones_silabo`): revisión, archivo Word, archivo PDF, clave
  de idempotencia y estado (`pendiente`, `en_ejecucion`, `completado`, `fallido`).
  Completada exige ambos archivos.
- **Firma** (`firmas_documento`): exportación, rol con que firma (`docente`,
  `coordinacion`, `biblioteca`; una de cada una), usuario, PDF firmado y datos del
  certificado (titular, número, entidad emisora y vigencia).

## IA (`AiAssistance`)

- **Mensaje de IA** (`mensajes_ia`): sílabo, usuario y contenido JSON con tipo
  (`mensaje` u `ocultamiento`), estado y entrada con clave de idempotencia.

## Operación (`Operations`)

- **Notificación** (`notificaciones`): usuario, clave única (evita duplicados), tipo,
  título, mensaje, recurso y fecha de lectura.
- **Evento de auditoría** (`eventos_auditoria`): acción, recurso, resultado (`exito` o
  `fallido`), detalles y una copia del nombre, rol y carrera del actor.
- **Ayuda de pantalla** (`ayudas_pantalla`): ruta de la pantalla (única), título,
  contenido, activa y quién la editó.

## Invariantes

1. Toda acción protegida exige usuario activo y permiso sobre el registro.
2. Una carrera tiene como máximo un coordinador activo.
3. Un paralelo tiene como máximo un docente activo.
4. Hay una convocatoria por período y solo una abierta o pausada a la vez.
5. Hay un sílabo por paralelo en cada convocatoria de carrera.
6. Todo envío crea una revisión nueva; nunca modifica la anterior.
7. Una revisión enviada no se edita. Solo desaparece si se elimina o se rehace el
   sílabo completo.
8. Revisión de coordinación, envío a biblioteca y aprobación se registran una sola vez
   por revisión.
9. Word y PDF se generan desde la copia guardada en la revisión.
10. Las firmas siguen el orden docente → coordinación → biblioteca.
11. La IA no cambia estados ni escribe en el sílabo.
12. Redis y el servicio de IA pueden fallar sin dañar el sílabo.
13. El total de horas de una asignatura es la suma de sus componentes.
