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
| UI-03 | Notificaciones | `/{área}/notificaciones` | Solo avisos del período en curso. Abre en «No leídas»; también «Todas», «Marcar todas como leídas» y «Abrir». También en la campana del encabezado, que abre siempre en «No leídas». Por correo solo llegan tres avisos del docente (convocatoria abierta, corrección solicitada y sílabo aprobado) y, a Coordinación, un resumen diario si tiene sílabos por revisar o firmar. |
| UI-04 | Perfil y seguridad | `/{área}/configuracion/perfil`, `/{área}/configuracion/seguridad` | Perfil: nombre y correo (solo si la cuenta lo permite) y, para el docente, «Formación y experiencia académica-investigativa». Seguridad: contraseña y segundo factor con códigos de recuperación. |

`{área}` es `admin`, `coordinacion`, `docente` o `biblioteca`. Una ruta inexistente
muestra «Página no encontrada».

## Administrador

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| ADM-01 | Antes de empezar | checklist flotante en todas las pantallas con menú (Driver.js) | Botón flotante de check, rojo y con un latido suave mientras falten pasos, arriba del de ayuda en la esquina inferior derecha; se abre solo la primera vez y se reabre cuando se quiera. Pasos previos a abrir la convocatoria (facultades, carreras, cuentas, coordinadores y plantilla); al pulsar uno, resalta en el menú lateral el módulo donde se hace, y el resaltado se cierra solo al cambiar de pantalla. Un check al completarlo. «Nueva convocatoria» queda deshabilitada hasta completarlos. |
| ADM-02 | Usuarios y roles | `/admin/usuarios` | Tabla de cuentas con la columna «Roles» para sus roles vigentes; «Crear cuenta» e «Importar desde Excel». |
| ADM-03 | Detalle de la cuenta | hoja lateral en ADM-02 | «Editar» muestra el nombre en cuatro campos (primer y segundo nombre, primer y segundo apellido), igual que «Crear cuenta»; un nombre antiguo con menos de cuatro palabras se puede dejar así. Roles vigentes y finalizados, «Agregar otro rol» (Coordinador: si la carrera ya tiene uno, pide confirmar el reemplazo), «Quitar rol», «Reenviar invitación», «Deshabilitar/Habilitar acceso», «Eliminar cuenta pendiente». |
| ADM-04 | Facultades y carreras | `/admin/estructura-academica` | Pestañas Facultades (campus, logo y bibliotecas de Koha donde buscan sus libros los docentes: casillas con las bibliotecas disponibles por su nombre; en «Editar facultad» es la pestaña «Bibliotecas» y en «Nueva facultad» el segundo paso, tras los datos) y Carreras (modalidad, ciclos, coordinador vigente, importación desde Excel; el coordinador se asigna en ADM-02). Facultades y carreras no se eliminan: «Desactivar» (en rojo) y «Reactivar», con la etiqueta «Inactiva». Se pueden editar siempre, también con una convocatoria abierta. Mientras haya una abierta, bajo la barra superior sale el aviso «Hay una convocatoria abierta · Si cambia una facultad o carrera, sus sílabos enviados o aprobados vuelven a Borrador»; si el cambio los afecta, pide confirmar. «Desactivar» sale deshabilitado, con el motivo en un tooltip, mientras el servidor no lo permita: una facultad con carreras activas; una carrera con usuarios activos o con una convocatoria en curso. |
| ADM-06 | Plantilla | `/admin/plantilla` | Ver y editar la plantilla única. Detalle abajo. |
| ADM-10 | Auditoría | `/admin/auditoria` | Registro paginado: fecha, actividad y responsable, todo con el mismo tamaño de letra. Filtros de lista, sin búsqueda de texto: período (por sus fechas), rol, carrera, actividad y resultado. |
| ADM-12 | Convocatorias | `/admin/convocatorias` | Entrada del Administrador; no tiene Panel. Arriba, avance del proceso, días para la entrega y carreras sin convocar, y, flotante, el checklist «Antes de empezar». Una fila por período. Detalle abajo. |

## Coordinador

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| COR-01 | Antes de empezar | checklist flotante en todas las pantallas con menú (Driver.js) | Botón flotante de check, rojo y con un latido suave mientras falten pasos, arriba del de ayuda en la esquina inferior derecha; se abre solo la primera vez y se reabre cuando se quiera. Pasos previos a abrir la convocatoria de la carrera (malla, materias del período, paralelos y docentes); al pulsar uno, resalta en el menú lateral el módulo donde se hace, y el resaltado se cierra solo al cambiar de pantalla. Un check al completarlo. «Abrir convocatoria» queda deshabilitado hasta que todo esté listo; su ayuda emergente dice qué falta. |
| COR-04 | Seguimiento | pestaña en COR-05 | Todos los sílabos de la convocatoria con paralelos, docentes y estado; en revisión indica la etapa («En coordinación» o «En biblioteca»). La fila de un sílabo ya enviado abre su revisión (COR-06). |
| COR-05 | Revisión | `/coordinacion/revisiones` | Entrada de Coordinación; no tiene Panel. Aviso del plazo bajo la barra superior. Sin indicadores; arriba, las acciones «Abrir», «Pausar» o «Reanudar convocatoria»; flotante, el checklist «Antes de empezar». Pestañas «Cola de revisión» (solo lo que espera la decisión de Coordinación: asignatura, docente, número de revisión y fecha de envío; sin filtro de estado) y «Seguimiento» (COR-04), donde está todo lo demás: en biblioteca, devueltos y aprobados. |
| COR-06 | Detalle de revisión | `/coordinacion/revisiones/:revision` | A pantalla completa, sin menú lateral, con «Volver a la cola». Junto al título, el estado como insignia y notas en texto («En biblioteca», «Devuelto por biblioteca», «Convocatoria cerrada · solo consulta», «Versión N (anterior) · solo consulta»); debajo, el período y el docente. De lo que hace Biblioteca no ve comentarios ni quién verificó. El sílabo se ve como el documento final. Decisiones en la cabecera, con diálogos: «Solicitar corrección» y «Firmar y enviar a biblioteca». Íconos: «Historial» y, mientras el sílabo está en revisión y desde la segunda versión, «Comparar con la versión anterior» (muestra la versión anterior al lado, en la misma pantalla, y marca con «Cambió» en ambas los campos que cambiaron). Con el sílabo aprobado, el ícono «Ver PDF» abre el PDF en una ventana aparte. Reiniciar está en «Más». «Historial» abre COR-11. |
| COR-08 | Solicitar corrección | diálogo en COR-06 | «Comentario para el docente» y «Solicitar corrección»; no se firma. |
| COR-09 | Firmar y enviar a biblioteca | diálogo en COR-06 | Archivo `.p12`/`.pfx`, clave y confirmar: firma y pasa a Biblioteca. |
| COR-10 | Reiniciar sílabo | «Más» en COR-06 | Única acción para descartar un sílabo, en rojo y separada de «Historial». El diálogo dice qué se borra con números (firmas y, si está aprobado, revisiones y PDF), pide el motivo (lo ve el docente y queda en la auditoría) y, en un sílabo aprobado, escribir el nombre de la materia; no hay cuenta regresiva. |
| COR-11 | Historial de versiones | `/coordinacion/revisiones/:revision/historial` | A pantalla completa, como el historial de versiones de Google Docs. A la izquierda, el documento de la versión elegida; a la derecha, el panel «Historial de versiones» con las versiones de la más nueva a la más vieja, cada una con un título que dice qué pasó («Primer envío», «Corregida tras observaciones», «Aprobada»), agrupados por día, con «Versión actual» marcada y, bajo cada versión, sus correcciones y aprobaciones. De Biblioteca solo «lo devolvió al docente». Los campos que cambiaron desde la versión anterior se marcan solos en el documento (la primera versión no marca nada). Junto a la barra de desplazamiento del documento salen marcas amarillas donde hubo cambios; al pulsar una, el documento baja hasta ese campo. |
| COR-13 | Malla | `/coordinacion/malla` | Lienzo con ciclos, materias y requisitos; «Editar malla» abre el editor a pantalla completa; «Importar desde Excel». |
| COR-14 | Materias y asignaciones | `/coordinacion/materias-asignaciones` | Materias con paralelos, jornada y docente; «Acciones masivas»; un clic abre «Paralelos y docentes de la materia». |
| COR-15 | Docentes | `/coordinacion/docentes` | Docentes de la carrera; «Crear docente», «Importar desde Excel»; la fila abre «Editar docente» con «Reenviar invitación» y «Quitar de la carrera». |
| COR-16 | Proyecto de carrera | `/coordinacion/proyecto-carrera` | Pestañas «Materias» (proyectos de carrera de las asignaturas e importación mediante plantilla Excel) y «Documentos de apoyo» (COR-17). Detalle abajo. |
| COR-17 | Documentos de apoyo | pestaña en COR-16 (`/coordinacion/proyecto-carrera?tab=documentos`; `/coordinacion/documentos-apoyo` redirige ahí) | Material de la carrera para sus docentes (proyecto de carrera, TIC y TAC, habilidades blandas, etc.). «Subir documento» acepta un PDF, Word o Excel de hasta 20 MB y pide escribir su nombre. Tabla con documento, tamaño y fecha de subida, sin tipo ni quién lo subió: todos se ven como PDF. Pulsar la fila abre el documento en una ventana aparte. «Subir documento» va en la cabecera solo con esta pestaña abierta (con «Materias», «Importar desde Excel»). Botón de papelera en la fila (tooltip «Eliminar»), con confirmación, que borra el documento del servidor; al abrir un documento no aparece indicador de carga junto al nombre. Word y Excel muestran «Preparando vista» hasta que el servidor los pasa a PDF; si no se puede, «No se pudo preparar la vista» y hay que volver a subirlo. No depende de la convocatoria ni muestra el aviso de edición bloqueada. |

COR-06 también ofrece «Reiniciar sílabo» (COR-10) solo con el sílabo aprobado y la convocatoria abierta o pausada,
y el botón «Documentos» (DOC-10).

## Docente

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| DOC-02 | Mis sílabos | `/docente/mis-silabos` | Entrada del docente; no tiene Panel ni indicadores. Bajo la barra superior, un aviso con los días que quedan para enviar sus sílabos a revisión y la fecha límite (en rojo cuando venció). Debajo, tarjetas del período vigente, hasta tres por fila: asignatura, paralelo y estado. Botón «Historial». |
| DOC-04 | Sílabo | `/docente/mis-silabos/:syllabus` | Editor a pantalla completa, sin menú lateral. Detalle abajo. |
| DOC-05 | Validación | dentro de DOC-04 | Campos faltantes y reglas de bloques; el botón de envío explica qué falta. |
| DOC-06 | Asistente de unidades | botón flotante en DOC-04 | Solo mientras el sílabo se edita (Borrador o Corrección solicitada). «Asistente» abre el chat de IA con preguntas sugeridas y «Nuevo chat»; «Cerrar asistente» lo oculta. |
| DOC-07 | Envío | diálogo en DOC-04 | Primer envío: «Firmar y enviar» pide el archivo `.p12`/`.pfx` y la clave. Reenvío tras una corrección: «Enviar», sin firmar. |
| DOC-08 | Corrección | aviso en DOC-04 | Solo con el sílabo devuelto: aviso ámbar «Coordinación pidió correcciones» o «Biblioteca pidió correcciones» con el último comentario, autor y fecha. Los motivos de Biblioteca salen como lista, uno por línea (también en el historial de versiones). En revisión o aprobado no se muestran comentarios. Al reenviar, el diálogo «¿Enviar a revisión?» dice que el sílabo pasa a revisión y pide asegurarse de haber hecho los arreglos que pidió Coordinación o Biblioteca, según quién pidió la corrección. |
| DOC-09 | Historial | `/docente/historial`, `/docente/historial/:career` | Carreras con sílabos de períodos terminados y, dentro, sus sílabos por período, en solo lectura. |
| DOC-11 | Documentos de apoyo | `/docente/documentos-apoyo` | Los documentos de apoyo de la carrera del rol activo, en solo lectura y sin botones de acciones: pulsar la fila los abre en una ventana aparte. |
| DOC-10 | Documentos | `/docente/revisiones/:revision/documentos`, `/coordinacion/revisiones/:revision/documentos` | Ver y descargar el PDF de la revisión aprobada apenas se genera, con los sellos de las tres firmas. Sin Word. «Reintentar» si falló. |

## Bibliotecario

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| BIB-02 | Revisiones de biblioteca | `/biblioteca/revisiones` | Entrada de Biblioteca; no tiene Panel. El período va en el título («Revisiones de biblioteca - Período 2026-2027»), sin insignia aparte; sin período en curso el título queda solo. Aviso del plazo para revisar bajo la barra superior. Arriba, tres indicadores: en revisión, aprobados y corrección solicitada. Debajo, bandeja de toda la universidad con el período en curso, un sílabo por fila (su envío más reciente), con las columnas asignatura, carrera, docente, responsable y estado («Sin asignar» si nadie lo ha devuelto aún): búsqueda por asignatura, código, carrera o docente, facultad y estado («En revisión», «Aprobados», «Corrección solicitada»). Cada fila muestra su estado con las mismas palabras que el sílabo: «En revisión», «Aprobado», «Corrección solicitada» (y «Anterior» si el docente ya reenvió una versión más nueva). «Atendido» junta los envíos firmados y aprobados por biblioteca, los devueltos al docente y los reemplazados por una revisión nueva; el detalle dice cuál de los tres fue. Columnas: carrera, docente y estado. Los bibliotecarios se reparten el trabajo por facultad: cada uno ve la última facultad que eligió. |
| BIB-03 | Revisión de bibliografía | `/biblioteca/revisiones/:revision` | Solo asignatura, carrera, docente y la bibliografía; sin número de revisión ni «Enviado por». Si otro bibliotecario lo devolvió antes, la pantalla avisa quién lo atiende («Lo atiende Ana, que lo devolvió antes») y, al pulsar «Devolver» o «Firmar», pregunta si quiere continuar; no se bloquea. Botones en la cabecera: «Devolver al docente» (motivos) y «Firmar y aprobar»; no hay ícono para ver el sílabo completo antes de aprobarlo. Con el sílabo aprobado, también en el historial, aparece el ícono «Ver PDF» (con su ayuda emergente) para abrir el sílabo completo en una ventana, desde donde se puede descargar; sirve para las auditorías. |
| BIB-04 | Historial | `/biblioteca/historial` | Sílabos de períodos terminados, en solo lectura; se llega desde «Historial» en BIB-02 y tiene «Volver a revisiones» arriba; BIB-03 dice «Volver al historial» y regresa ahí si se llegó desde el historial. Columnas: período, carrera, docente y «Firmado por» (quien firmó la bibliografía; «—» si fue devuelto). Filtros por período, facultad y «Solo los míos» (los que el bibliotecario firmó o devolvió). La fila abre BIB-03. |

## Detalle de pantallas clave

### ADM-06 Plantilla

- Abre en modo lectura: las hojas muestran la plantilla como quedará el sílabo, con los
  datos del sistema vacíos (sin valores de muestra). Arriba: «Opciones avanzadas» y la acción principal «Editar»,
  alineadas con el borde derecho de la primera hoja. La barra respeta su papel,
  orientación y el ancho disponible de pantalla, también durante la edición.
- En las plantillas protegidas, los párrafos y celdas vacíos son espacios de respuesta.
  El editor no muestra un aviso sobre esto. No hay controles por celda.
- «Editar» pasa a pantalla completa, sin menú lateral: arriba, «Volver a la vista», el
  título y el cambio de tema. «Volver a la vista» pide confirmar si hay cambios sin
  guardar. Activa la cinta de edición sobre las mismas hojas:
  - formato de texto (negrita, cursiva, subrayado, color del texto, alineación, listas). El
    color del texto solo ofrece negro, azul (`#365F91`, el de los rótulos de las unidades) y
    blanco;
  - menú «Tabla» (insertar, filas, columnas, combinar, separar, encabezado, fondo de
    celda, quitar). El fondo de celda solo ofrece los colores de la tabla de
    identificación: «Celeste» (en negrita) y «Azul institucional» (texto blanco y negrita),
    o «Sin fondo»;
  - con un campo elegido: «Quién llena» (Docente, Carrera, Fijo), «Plantilla» (inserta
    Identificación institucional, Distribución y planificación, Bibliografía básica o
    complementaria, o Firmas), nombre, subir, bajar y eliminar;
  - con una sección elegida: vertical u horizontal, subir, bajar y eliminar;
  - un ícono con ayuda al pasar el cursor, junto a las demás herramientas: «Opciones
    avanzadas», cuyo menú tiene «Diseño», «Listas» y «Editar JSON»;
  - «Diseño» (diálogo): márgenes y tamaños de letra;
  - «Listas» (diálogo): mínimo, máximo y «Empieza con» (cuántos elementos ya aparecen al abrir el sílabo) de
    cada lista de los bloques, por ejemplo «Unidades» y «Semanas por unidad». La lista de libros de la
    bibliografía no aparece aquí: es parte del diseño de la plantilla;
  - «Guardar» (también Ctrl+S). Nada se guarda hasta «Guardar».
- En la hoja se agregan secciones y campos («Nueva sección», «Agregar campo»), se editan
  títulos en línea y se cambia el logo de la universidad.
- «Opciones avanzadas → Editar JSON» abre «Plantilla como JSON»: «Descargar», «Cargar archivo»
  y «Aplicar y reemplazar». Si hay errores, no cambia nada y muestra la ruta de cada uno. Los bloques
  solo se agregan por JSON.
- Con la convocatoria abierta, todo queda bloqueado y aparece el aviso de edición
  bloqueada. Si la plantilla cambió durante una pausa, se avisa que al reanudar se
  rehacen los sílabos.
- Asistente (POR VALIDAR): solo al editar, botón flotante «Asistente» abajo a la derecha.
  Abre un panel de chat donde se pide un cambio en lenguaje natural. La IA trabaja sobre lo
  que hay en el editor, aunque no esté guardado. Cada propuesta lista sus cambios con
  «Aplicar» y «Descartar». «Aplicar» pone los cambios en el editor y los marca «Cambió»
  hasta guardar o salir; se guardan con «Guardar», como cualquier cambio. Si el borrador
  cambió después del pedido, la propuesta queda «Vencida» y no se aplica. Un pedido nuevo
  reemplaza la propuesta pendiente. «Nuevo chat» limpia la conversación.

### ADM-12 Convocatorias

- Columnas: período, clases, elaboración de sílabos y estado («Sin iniciar», «Abierto»,
  «Pausado», «Finalizado»).
- «Nueva convocatoria» pide en dos pasos las fechas de clases (con el aviso «Serían N
  semanas de clase entre las fechas», no editable) y las de elaboración y
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

- Materias agrupadas por ciclo, con su estado: «Completa», «n de m campos» o
  «Incompleta». Filtros por materia, ciclo y estado.
- Un clic abre «Proyecto de carrera de {materia}»: pestañas por sección, un editor por campo y
  «Guardar proyecto de carrera». Un campo vacío muestra «Sin llenar».
- «Importar desde Excel», con la misma variante verde de las otras importaciones,
  abre «Importar el proyecto de carrera»:
  1. «Descargar plantilla Excel» genera las materias y los campos de Coordinación;
  2. completar las celdas de contenido, manteniendo nombres, encabezados y materias;
  3. cargar un `.xlsx` y pulsar «Revisar Excel»;
  4. revisar «n de m campos con contenido», tablas y comparación con el proyecto de carrera actual;
  5. seleccionar materias y campos y «Aplicar a N materias», o «Descartar».
  La vista previa solo dura mientras el diálogo esté abierto. Al cerrar o recargar
  hay que volver a subir el archivo; «Descartar» limpia únicamente la pantalla.
- Los campos vacíos conservan sus valores actuales. El servidor señala hoja y celda
  de los errores; si cambió la estructura, exige descargar una plantilla nueva.
- El flujo funciona sin IA, OCR ni cola. Solo se ofrecen vistas previas Excel; la carga
  antigua de documentos y su progreso asíncrono fueron retirados.
- Con la convocatoria de la carrera abierta, el proyecto de carrera y la importación quedan bloqueados.

### DOC-04 Sílabo

- Al abrir un sílabo sin iniciar, el borrador empieza solo.
- Título con la materia, sin ícono; debajo, el período. Con el documento a la vista, el encabezado mide lo mismo que la hoja. Sin «Formación y experiencia» en el perfil, un modal obliga a completarla (o a volver a «Mis sílabos») antes de ver cualquier sílabo.
- Formulario con los componentes del sistema, generado desde la plantilla: pestañas solo
  para las secciones con campos del docente, un campo con etiqueta por dato y asterisco
  de obligatorio, las unidades en un acordeón, y las semanas y las tablas de respuesta
  (como la bibliografía) en tablas del sistema con «Agregar» y «Quitar». Las horas solo
  admiten números. «Anterior» y «Siguiente», debajo de la tarjeta, recorren las secciones. Cada campo se guarda solo
  tras una breve pausa; si falla, muestra «Sin guardar» y «Reintentar»; si hay
  conflicto, «Recargar borrador».
- Campos del docente: editables. Campos del proyecto de carrera: solo lectura, salvo los marcados
  como editables («Viene del proyecto de carrera; puede ajustarlo.»). Lo institucional se ve en
  «Vista previa».
- Bloques: unidades y semanas con contador de horas, y discapacidad (los datos aparecen
  solo si responde Sí).
- En revisión o aprobado, la pantalla muestra el documento tal como sale. La tabla «Revisión y
  aprobación» ya lleva el sello y la fecha de cada firma que se hizo (docente al enviar,
  coordinación y biblioteca al decidir); lo que falta sigue con su rótulo.
- «Vista previa» (un ojo, con tooltip) muestra la hoja del sílabo en la pantalla, como en
  Plantilla. Es un solo botón: queda presionado mientras se ve la hoja y, al pulsarlo otra vez, vuelve al formulario. No cambia de ícono ni de lugar. Con el sílabo aprobado, el ícono «Ver PDF» (igual que en Biblioteca y Coordinación) abre
  el PDF directamente en otra pestaña. «Copiar desde paralelo X» trae lo escrito en
  otro paralelo propio.
- «Firmar y enviar» (primer envío) o «Enviar» (reenvío ya firmado) queda deshabilitado
  mientras falten campos («Faltan N campos por completar»).

### Diálogo de firma

Lo usan «Firmar y enviar» (DOC-04), «Firmar y enviar a biblioteca» (COR-06) y «Firmar y
aprobar» (BIB-03): archivo `.p12` o `.pfx`, clave y confirmar. La firma se hace en el
navegador sobre la declaración que entrega el servidor; el archivo y la clave no se
envían. Cada persona firma una sola vez por sílabo.

### BIB-03 Revisión de bibliografía

- «Firmar y aprobar» abre el diálogo de firma; el sílabo queda aprobado y se genera el PDF.
- «Devolver al docente» pide al menos un motivo: libro no disponible, código incorrecto,
  ISBN incorrecto, edición desactualizada, datos incompletos u «Otro motivo». Con «Otro
  motivo» aparece un cuadro obligatorio; su texto se envía junto a los motivos marcados.

## Patrones comunes

- **Menú lateral por rol.** Administrador: Convocatorias, Usuarios y roles,
  Facultades y carreras, Plantilla, Auditoría. Coordinador:
  Revisión, Materias y asignaciones, Docentes, Proyecto de carrera, Malla. Docente y
  Bibliotecario no tienen menú lateral: el logo va a la izquierda del encabezado y lleva a
  su única pantalla. Se reactiva quitando el rol de `ROLES_WITHOUT_SIDEBAR`
  (`useShowsSidebar.ts`).
- **Encabezado.** Campana de avisos, tema claro u oscuro y menú de usuario
  (Configuración, Cerrar sesión). Con más de un rol, el menú de usuario ofrece «Cambiar
  carrera o rol», con o sin menú lateral: cierra el rol activo de la sesión y lleva a `/rol`
  (UI-02), sin ventana emergente. En `/rol` la opción no aparece, porque ya no hay rol activo;
  el encabezado muestra el logo horizontal de la UEB, igual que el resto de pantallas. El pie del menú lateral ofrece «Instalar
  aplicación» mientras el sistema no corre instalado; sin menú lateral, esa opción
  también está en el menú de usuario.
- **Avisos.** Solo llegan cuando hay algo que hacer o cambió el trabajo propio. Abrir un
  aviso lleva a la pantalla donde se actúa y, si hace falta, activa el rol que
  corresponde. Cada aviso pertenece a un período; al terminar el período deja de verse.
  - Docente: «Tiene sílabos por elaborar», «Plazo extendido», «Corrección solicitada»,
    «Sílabo aprobado» (cuando el PDF está listo) y «Su sílabo volvió a empezar»
    (reinicio, eliminación, cambio de la base o de la plantilla).
  - Coordinación de la carrera: «Sílabo listo para revisión».
  - Biblioteca: «Sílabo listo para revisión» (también el reenvío corregido).
  - Administración: «No se pudieron generar los documentos».
- **Accesibilidad.** Botón flotante en escritorio (en móvil, en el menú de usuario):
  tamaño del texto, alto contraste, sin animaciones y texto más
  espaciado. Se guarda en la cuenta.
- **Ayuda de pantalla.** Botón flotante fijo «Ayuda de esta pantalla», justo arriba del de
  accesibilidad (esquina inferior derecha), cuando la pantalla tiene texto en
  `ScreenHelpCatalog` (backend). Al pulsarlo empieza una guía con Driver.js: un primer cuadro
  con el texto de la pantalla y después un paso por cada elemento clave, resaltado sobre la
  pantalla real, con «Anterior», «Siguiente», «Listo» y el avance «2 de 5». Los pasos están en
  `screenTours.ts` (frontend); si un elemento no está en pantalla en ese momento, se omite. En
  las pantallas de entrada de cada rol la guía termina con el menú, las notificaciones, la
  cuenta y la accesibilidad. Sin pasos, muestra solo el texto. Los textos no se editan desde
  el sistema.
- **Edición bloqueada.** Una franja a todo el ancho, bajo la barra superior, dice
  «Edición bloqueada · Convocatoria {período}» y qué pausar, con «¿Por qué?» e «Ir a
  Convocatorias» («Ir a Revisión» para Coordinación). Convocatorias usa la misma franja
  para «Convocatoria en curso». El servidor rechaza igual la petición.
- **Aviso de plazo.** Docente, Coordinación y Biblioteca ven en todas sus pantallas, bajo
  la barra superior, una franja ámbar con los días que quedan, qué les toca hacer antes
  del plazo de entrega y la fecha límite. Biblioteca usa el plazo de la convocatoria
  institucional. El editor del sílabo, a pantalla completa, no la muestra. Con el plazo
  vencido la franja sale en rojo: al docente le dice que pida una prórroga a Administración
  y a Coordinación y Biblioteca que solo pueden revisar lo ya enviado.
- **Sin Panel.** Cada rol entra a su lista de trabajo y ve ahí sus indicadores:
  «Convocatorias», «Revisión», «Mis sílabos» y «Revisiones de biblioteca». La puesta en
  marcha de Administración y de Coordinación es un modal en su pantalla de entrada.
- **Tablas.** Búsqueda «Buscar por…», filtros y 20 filas por página. La fila abre el
  detalle. En pantallas angostas, cada fila se vuelve una tarjeta. La búsqueda no distingue
  tildes ni mayúsculas, y buscar vuelve a la primera página. En las tablas que consultan
  al servidor, los filtros y la página quedan en la dirección y «Quitar filtros» aparece
  siempre que haya alguno activo.
- **Formularios.** Las altas se hacen en un modal o en una hoja lateral. Los datos
  obligatorios llevan asterisco.
- **Estados.** Mismas etiquetas y colores en todo el producto. Las cargas muestran
  esqueletos con la forma de la pantalla. Los botones de la pantalla aparecen cuando
  termina de cargar, nunca antes ni deshabilitados mientras carga.

## IDs retirados

- ADM-05 (Plantillas) y ADM-07 (Publicar): hay una sola plantilla, sin publicación.
- ADM-09 (Trabajos fallidos): sin pantalla.
- ADM-11 (Configuración): es UI-04.
- COR-02 y COR-03 (Convocatorias de carrera): están en COR-01 y COR-05.
- Al firmar, igual que el docente al firmar y enviar: Coordinación («Firmar y enviar a biblioteca» y «Solicitar corrección») vuelve sola a la cola de Revisión y Biblioteca («Firmar y aprobar» y «Devolver al docente») vuelve sola a sus revisiones, con el aviso de lo hecho. No se quedan en el detalle.
- COR-07 (Comparar revisiones): la comparación está en COR-06, al lado de la versión anterior. Los campos que cambiaron se resaltan con «Cambió» solo en la versión actual y, como en el historial, llevan una marca amarilla en el borde derecho de la pantalla, debajo del encabezado; pulsarla lleva al campo. El botón «Resaltar cambios» del encabezado quita o vuelve a poner el resaltado y las marcas; queda deshabilitado, con un tooltip, si no hay cambios. Lo mismo en el historial de versiones.
- COR-11 (Fuentes académicas): retirada.
- COR-12 (Informes): el avance está en COR-05.
- DOC-03 (Resumen del sílabo): la vista previa está en DOC-04.
- DEC-01 y DEC-02 (Decanato): el rol no existe.
