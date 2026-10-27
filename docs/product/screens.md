# Pantallas

Inventario de la SPA (`proyecto-grado-silabos-frontend`, rutas en `src/router.ts`). Los
IDs sirven para la trazabilidad. Las rutas `/panel`, `/notificaciones`,
`/configuracion/*` y `/revisiones/:revision/documentos` redirigen a la ruta del rol
activo.

## Globales

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| UI-01 | Acceso | `/ingresar`, `/recuperar-contrasena`, `/restablecer-contrasena`, `/activar-cuenta`, `/verificar-correo`, `/verificar-dos-factores` | Iniciar sesión, recuperar la contraseña, activar la cuenta, verificar el correo y pasar el segundo factor. |
| UI-02 | Selección de ámbito | `/rol` | Una tarjeta por rol y carrera. Aparece cuando no hay rol activo (ver `roles-and-permissions.md`). |
| UI-03 | Notificaciones | `/{área}/notificaciones` | «Todas» o «No leídas», «Marcar todas como leídas», «Abrir». También en la campana del encabezado. |
| UI-04 | Perfil y seguridad | `/{área}/configuracion/perfil`, `/{área}/configuracion/seguridad` | Perfil: nombre y correo (solo si la cuenta lo permite) y, para el docente, «Formación y experiencia académica-investigativa». Seguridad: contraseña y segundo factor con códigos de recuperación. |

`{área}` es `admin`, `coordinacion`, `docente` o `biblioteca`. Una ruta inexistente
muestra «Página no encontrada».

## Administrador

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| ADM-01 | Panel | `/admin/panel` | Avance del proceso, días para la entrega y carreras sin convocar. Lista «Puesta en marcha de la institución». |
| ADM-02 | Usuarios y roles | `/admin/usuarios` | Tabla de cuentas con la columna «Roles» para sus roles vigentes; «Crear cuenta» e «Importar desde Excel». |
| ADM-03 | Detalle de la cuenta | hoja lateral en ADM-02 | Roles vigentes y finalizados, «Agregar otro rol», «Quitar rol», «Reenviar invitación», «Deshabilitar/Habilitar acceso», «Eliminar cuenta pendiente». |
| ADM-04 | Facultades y carreras | `/admin/estructura-academica` | Pestañas Facultades (campus y logo PNG) y Carreras (modalidad, ciclos, «Asignar/Reemplazar coordinador», importación desde Excel). |
| ADM-06 | Plantilla | `/admin/plantilla` | Ver y editar la plantilla única. Detalle abajo. |
| ADM-10 | Auditoría | `/admin/auditoria` | Registro paginado: fecha, actividad y responsable. |
| ADM-12 | Convocatorias | `/admin/convocatorias` | Una fila por período. Detalle abajo. |
| ADM-13 | Ayuda de pantallas | `/admin/ayuda` | Tabla de pantallas por rol con estado «Listo» (la ayuda se ve) o «Pendiente» (sin texto u oculta). Se edita el texto y si se muestra. |

## Coordinador

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| COR-01 | Puesta en marcha | `/coordinacion/puesta-en-marcha` | Pasos para preparar la carrera y «Abrir convocatoria». Fuera del menú lateral: se llega desde «Puesta en marcha» en COR-05. |
| COR-04 | Seguimiento | pestaña en COR-05 | Todos los sílabos de la convocatoria con paralelos, docentes, estado y fecha de inicio. |
| COR-05 | Revisión | `/coordinacion/revisiones` | Entrada de Coordinación; no tiene Panel. Aviso del plazo bajo la barra superior. Arriba, avance, por revisar y sin iniciar; acciones «Abrir», «Pausar» o «Reanudar convocatoria» y «Puesta en marcha». Pestañas «Cola de revisión» (asignatura, docente, número de revisión, estado y etapa) y «Seguimiento» (COR-04). |
| COR-06 | Detalle de revisión | `/coordinacion/revisiones/:revision` | Contenido enviado en solo lectura, historial de revisiones, decisión y firma. |
| COR-07 | Comparar revisiones | `/coordinacion/revisiones/:before/comparar/:after` | Campos agregados, retirados o modificados, lado a lado. |
| COR-08 | Solicitar corrección | tarjeta en COR-06 | «Comentario para el docente» y «Solicitar corrección». |
| COR-09 | Aprobar | tarjeta en COR-06 | «Aprobar revisión N»: pasa a Biblioteca. |
| COR-10 | Eliminar sílabo aprobado | tarjeta en COR-06 | «Eliminar sílabo» → «Eliminar definitivamente». |
| COR-13 | Malla | `/coordinacion/malla` | Lienzo con ciclos, materias y requisitos; «Editar malla» abre el editor a pantalla completa; «Importar desde Excel». |
| COR-14 | Materias y asignaciones | `/coordinacion/materias-asignaciones` | Materias con paralelos, jornada y docente; «Acciones masivas»; un clic abre «Paralelos y docentes de la materia». |
| COR-15 | Docentes | `/coordinacion/docentes` | Docentes de la carrera; «Crear docente», «Importar desde Excel»; la fila abre «Editar docente» con «Reenviar invitación» y «Quitar de la carrera». |
| COR-16 | Proyecto de carrera | `/coordinacion/proyecto-carrera` | Fichas de las asignaturas e importación mediante plantilla Excel. Detalle abajo. |

COR-06 también ofrece «Reiniciar sílabo» mientras está en borrador, revisión o corrección,
y el botón «Documentos» (DOC-10).

## Docente

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| DOC-02 | Mis sílabos | `/docente/mis-silabos` | Entrada del docente; no tiene Panel. Arriba, sílabos por entregar, por corregir y en revisión, Bajo la barra superior, un aviso ámbar con los días que quedan para enviar sus sílabos a revisión y la fecha límite. Debajo, tarjetas del período vigente: asignatura, paralelo, estado y último guardado. Botón «Historial». |
| DOC-04 | Sílabo | `/docente/mis-silabos/:syllabus` | Editor a pantalla completa, sin menú lateral. Detalle abajo. |
| DOC-05 | Validación | dentro de DOC-04 | Campos faltantes y reglas de bloques; el botón de envío explica qué falta. |
| DOC-06 | Asistente de unidades | panel en DOC-04 | Chat de IA con preguntas sugeridas y «Nuevo chat». |
| DOC-07 | Envío | diálogo en DOC-04 | «¿Enviar a revisión?» → «Enviar a revisión». |
| DOC-08 | Corrección | tarjeta en DOC-04 | «Comentario de Coordinación» o «Comentario de Biblioteca». |
| DOC-09 | Historial | `/docente/historial`, `/docente/historial/:career` | Carreras con sílabos de períodos terminados y, dentro, sus sílabos por período, en solo lectura. |
| DOC-10 | Documentos | `/docente/revisiones/:revision/documentos`, `/coordinacion/revisiones/:revision/documentos` | Word y PDF de la revisión aprobada; «Generar DOCX y PDF». Las descargas aparecen con las tres firmas. |

## Bibliotecario

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| BIB-02 | Revisiones de biblioteca | `/biblioteca/revisiones` | Entrada de Biblioteca; no tiene Panel. Aviso del plazo para revisar y firmar bajo la barra superior. Arriba, por verificar, por firmar y con comentario. Debajo, bandeja de toda la universidad con el período en curso: búsqueda, facultad y estado («Por revisar», «Por firmar», «Firmado», «Con comentario», «Anulado»). Columnas: carrera, docente y estado. Los bibliotecarios se reparten el trabajo por facultad: cada uno ve la última facultad que eligió. |
| BIB-03 | Revisión de bibliografía | `/biblioteca/revisiones/:revision` | Bibliografía en solo lectura, «Aprobar bibliografía», «Devolver al docente» con motivos, motivos enviados y firma. |
| BIB-04 | Historial | `/biblioteca/historial` | Sílabos de períodos terminados, en solo lectura; se llega desde «Historial» en BIB-02. Filtros por período, facultad, estado y «Solo los míos» (los que el bibliotecario aprobó, devolvió o firmó). La fila abre BIB-03. |

## Detalle de pantallas clave

### ADM-06 Plantilla

- Abre en modo lectura: las hojas muestran la plantilla con datos de muestra, como
  quedará el sílabo. Arriba: «Opciones avanzadas» y la acción principal «Editar»,
  alineadas con el borde derecho de la primera hoja. La barra respeta su papel,
  orientación y el ancho disponible de pantalla, también durante la edición.
- En las plantillas protegidas, los párrafos y celdas vacíos son espacios de respuesta.
  El editor no muestra un aviso sobre esto. No hay controles por celda.
- «Editar» activa la cinta de edición sobre las mismas hojas:
  - formato de texto (negrita, cursiva, subrayado, alineación, listas);
  - menú «Tabla» (insertar, filas, columnas, combinar, separar, encabezado, fondo de
    celda, quitar);
  - con un campo elegido: «Quién llena» (Docente, Carrera, Fijo), «Plantilla» (inserta
    Identificación institucional, Distribución y planificación, Bibliografía básica o
    complementaria, o Firmas), nombre, subir, bajar y eliminar;
  - con una sección elegida: vertical u horizontal, subir, bajar y eliminar;
  - «Diseño»: márgenes y tamaños de letra;
  - «Cancelar» y «Guardar» (también Ctrl+S). Nada se guarda hasta «Guardar».
- En la hoja se agregan secciones y campos («Nueva sección», «Agregar campo»), se editan
  títulos en línea y se cambia el logo de la universidad.
- «Opciones avanzadas → Editar JSON» abre «Plantilla como JSON»: «Descargar», «Cargar archivo»
  y «Aplicar y reemplazar». Si hay errores, no cambia nada y muestra la ruta de cada uno. Los bloques
  `evaluacion`, `si_no` y `repetible` solo se agregan por JSON.
- Con la convocatoria abierta, todo queda bloqueado y aparece el aviso de edición
  bloqueada. Si la plantilla cambió durante una pausa, se avisa que al reanudar se
  rehacen los sílabos.

### ADM-12 Convocatorias

- Columnas: período, clases, elaboración de sílabos y estado («Sin iniciar», «Abierto»,
  «Pausado», «Finalizado»).
- «Nueva convocatoria» pide en dos pasos las fechas de clases y las de elaboración y
  entrega, y abre la convocatoria. Está deshabilitado mientras otra siga abierta o
  pausada.
- Al pulsar una fila se abre un diálogo: sin convocatoria, «Abrir convocatoria» o
  «Eliminar periodo»; con convocatoria, «Pausar», «Reanudar», «Prorrogar plazo»,
  «Cerrar» y «Eliminar». «Cerrar» pide confirmación y deja el estado «Cerrado». Una
  cerrada ya no ofrece «Cerrar» ni «Eliminar». «Eliminar» pide confirmación y explica
  que se borra de verdad, y solo se ofrece si ningún docente ha enviado su sílabo; si
  ya hay sílabos enviados, el sistema lo dice y no deja eliminar. «Eliminar periodo»
  explica que solo se borran las fechas y las semanas lectivas del período, y que las
  materias, los paralelos y los docentes no se borran. Un período con convocatoria
  muestra el motivo y no deja eliminarlo.
- Si la plantilla cambió durante la pausa, «Reanudar» muestra el conteo por estado y
  pide «Reanudar y rehacer sílabos».

### COR-13 Malla

- «Editar malla» abre el lienzo a pantalla completa. La ayuda de la pantalla sigue
  disponible en la esquina inferior derecha para no cubrir los controles de zoom.
- En edición, ambos puntos «+» muestran el tooltip «Conectar materias».
- El formulario de la materia tiene el interruptor «Puede generar sílabos». La tarjeta de
  una materia que no genera muestra «Sin sílabo» junto a la modalidad, y COR-14 la marca
  con la etiqueta «Sin sílabo».

### COR-14 Materias y asignaciones y COR-15 Docentes

- COR-14 lista las materias de la malla con sus paralelos, jornada y docente. Filtros
  por ciclo, paralelo y jornada.
- «Acciones masivas» sobre las materias marcadas: agregar un paralelo, asignar jornada o
  asignar docente. «Agregar un paralelo» pide jornada y docente obligatorios para los
  nuevos paralelos; el código siguiente libre se calcula por materia.
- «Paralelos y docentes de la materia»: agregar, editar o eliminar paralelos y quitar o
  asignar docente. Cambiar el docente reinicia el sílabo del paralelo.
- Al agregar o editar un paralelo, el desplegable de Docente incluye «Buscar por nombre»
  y filtra sin distinguir mayúsculas ni tildes. Si no hay coincidencias muestra «No se
  encontraron docentes». Crear un paralelo exige código, jornada y docente; la API
  guarda el paralelo y su asignación juntos al confirmar.
- COR-15 muestra docente, correo y estado (activa o pendiente). En «Editar docente», el
  nombre y el correo se editan solo mientras la cuenta está pendiente, y «Quitar de la
  carrera» se deshabilita con la convocatoria de la carrera abierta.

### COR-16 Proyecto de carrera

- Materias agrupadas por ciclo, con el estado de su ficha: «Completa», «n de m campos» o
  «Incompleta». Filtros por materia, ciclo y estado.
- Un clic abre «Ficha de {materia}»: pestañas por sección, un editor por campo y
  «Guardar ficha». Cada campo tiene el interruptor «El docente puede editarlo».
- «Importar desde Excel», con la misma variante verde de las otras importaciones,
  abre «Importar el proyecto de carrera»:
  1. «Descargar plantilla Excel» genera las materias y los campos de Coordinación;
  2. completar las celdas de contenido, manteniendo nombres, encabezados y materias;
  3. cargar un `.xlsx` y pulsar «Revisar Excel»;
  4. revisar «n de m campos con contenido», tablas y comparación con la ficha actual;
  5. seleccionar materias y campos y «Aplicar a N materias», o «Descartar».
  La vista previa solo dura mientras el diálogo esté abierto. Al cerrar o recargar
  hay que volver a subir el archivo; «Descartar» limpia únicamente la pantalla.
- Los campos vacíos conservan sus valores actuales. El servidor señala hoja y celda
  de los errores; si cambió la estructura, exige descargar una plantilla nueva.
- El flujo funciona sin IA, OCR ni cola. Solo se ofrecen vistas previas Excel; la carga
  antigua de documentos y su progreso asíncrono fueron retirados.
- Con la convocatoria de la carrera abierta, la ficha y la importación quedan bloqueadas.

### DOC-04 Sílabo

- Al abrir un sílabo sin iniciar, el borrador empieza solo.
- Pestañas por sección. Cada campo se guarda solo tras una breve pausa; si falla,
  muestra «Sin guardar» y «Reintentar»; si hay conflicto, «Recargar borrador».
- Campos del docente: editables. Campos de la ficha: solo lectura, salvo los marcados
  como editables («Lo trae la ficha de la carrera; puede ajustarlo.»). Campos fijos:
  solo lectura.
- Los bloques se llenan dentro del editor: unidades y semanas con contador de horas,
  discapacidad, Sí/No y filas repetibles.
- «Vista previa» muestra el sílabo completo. «Copiar desde paralelo X» trae lo escrito en
  otro paralelo propio.
- «Enviar sílabo» queda deshabilitado mientras falten campos («Faltan N campos por
  completar»).
- Al aprobarse aparece la tarjeta de firma.

### Tarjeta de firma

Aparece en DOC-04, COR-06 y BIB-03 cuando la revisión está aprobada. Muestra los tres
firmantes en orden y su estado («Pendiente docente», «Pendiente coordinación»,
«Pendiente biblioteca», «Firmado»). «Firmar electrónicamente» pide el archivo `.p12` o
`.pfx` y su clave, firma en el navegador y sube solo el PDF firmado. «Descargar PDF
firmado» aparece con las tres firmas.

### BIB-03 Revisión de bibliografía

- «Aprobar bibliografía» pide confirmación; el sílabo queda aprobado y se genera el PDF.
- «Devolver al docente» pide al menos un motivo: libro no disponible, código incorrecto,
  ISBN incorrecto, edición desactualizada, datos incompletos u «Otro motivo». Con «Otro
  motivo» aparece un cuadro obligatorio; su texto se envía junto a los motivos marcados.

## Patrones comunes

- **Menú lateral por rol.** Administrador: Panel, Convocatorias, Usuarios y roles,
  Facultades y carreras, Plantilla, Auditoría, Ayuda de pantallas. Coordinador:
  Revisión, Materias y asignaciones, Docentes, Proyecto de carrera, Malla. Docente:
  Mis sílabos. Bibliotecario: Revisiones de biblioteca.
- **Encabezado.** Campana de avisos, tema claro u oscuro y menú de usuario
  (Configuración, Cerrar sesión). Con más de un rol, el pie del menú lateral ofrece
  «Cambiar carrera o rol».
- **Avisos.** Solo llegan cuando hay algo que hacer o cambió el trabajo propio. Abrir un
  aviso lleva a la pantalla donde se actúa y, si hace falta, activa el rol que
  corresponde.
- **Accesibilidad.** Botón flotante en escritorio (en móvil, en el menú de usuario):
  tamaño del texto, alto contraste, sin animaciones, subrayar enlaces y texto más
  espaciado. Se guarda en la cuenta.
- **Ayuda de pantalla.** Botón flotante y arrastrable «Ayuda de esta pantalla» cuando
  ADM-13 tiene un texto activo para esa pantalla.
- **Edición bloqueada.** Una franja a todo el ancho, bajo la barra superior, dice
  «Edición bloqueada · Convocatoria {período}» y qué pausar, con «¿Por qué?» e «Ir a
  Convocatorias» («Ir a Revisión» para Coordinación). Convocatorias usa la misma franja
  para «Convocatoria en curso». El servidor rechaza igual la petición.
- **Aviso de plazo.** Docente, Coordinación y Biblioteca ven en todas sus pantallas, bajo
  la barra superior, una franja ámbar con los días que quedan, qué les toca hacer antes
  del plazo de entrega y la fecha límite. Biblioteca usa el plazo de la convocatoria
  institucional. El editor del sílabo, a pantalla completa, no la muestra.
- **Panel.** Solo Administración: tres indicadores y «Puesta en marcha de la institución»
  hasta completar sus pasos. Los demás roles ven sus indicadores arriba de su lista de
  trabajo: «Revisión», «Mis sílabos» y «Revisiones de biblioteca».
- **Tablas.** Búsqueda «Buscar por…», filtros y 20 filas por página. La fila abre el
  detalle. En pantallas angostas, cada fila se vuelve una tarjeta.
- **Formularios.** Las altas se hacen en un modal o en una hoja lateral. Los datos
  obligatorios llevan asterisco.
- **Estados.** Mismas etiquetas y colores en todo el producto. Las cargas muestran
  esqueletos con la forma de la pantalla.

## IDs retirados

- ADM-05 (Plantillas) y ADM-07 (Publicar): hay una sola plantilla, sin publicación.
- ADM-09 (Trabajos fallidos): sin pantalla.
- ADM-11 (Configuración): es UI-04.
- COR-02 y COR-03 (Convocatorias de carrera): están en COR-01 y COR-05.
- COR-11 (Fuentes académicas): retirada.
- COR-12 (Informes): el avance está en COR-05.
- DOC-03 (Resumen del sílabo): la vista previa está en DOC-04.
- DEC-01 y DEC-02 (Decanato): el rol no existe.
