# Inventario de interfaces

I-146 (2026-09-27) retira COR-11 y los paneles del revisor de IA. DOC-06 conserva el
chat de elaboración con contexto académico/curricular; la importación I-145 conserva
documentos privados internos y revisión por lote, sin editor de fuentes. COR-11 queda
en el inventario como identificador histórico.

## Globales

| ID    | Pantalla                   |
| ----- | -------------------------- |
| UI-01 | Acceso                     |
| UI-02 | Selección de carrera y rol |
| UI-03 | Notificaciones             |
| UI-04 | Perfil y sesiones          |

## Docente

| ID     | Pantalla                        |
| ------ | ------------------------------- |
| DOC-01 | Panel docente                   |
| DOC-02 | Mis sílabos                     |
| DOC-03 | Resumen del sílabo              |
| DOC-04 | Editor por secciones            |
| DOC-05 | Validación determinística       |
| DOC-06 | Chat de elaboración de contenido y unidades                |
| DOC-07 | Confirmación de envío           |
| DOC-08 | Correcciones y respuestas       |
| DOC-09 | Historial y comparación         |
| DOC-10 | Sílabo aprobado y exportaciones |

## Coordinador

| ID     | Pantalla                                                      |
| ------ | ------------------------------------------------------------- |
| COR-01 | Panel de coordinación                                         |
| COR-02 | Convocatorias                                                 |
| COR-03 | Asistente de convocatoria                                     |
| COR-04 | Seguimiento de convocatoria                                   |
| COR-05 | Cola de revisión                                              |
| COR-06 | Espacio de revisión                                           |
| COR-07 | Comparar revisiones                                           |
| COR-08 | Solicitar corrección                                          |
| COR-09 | Aprobar                                                       |
| COR-10 | Reabrir aprobado                                              |
| COR-11 | Retirada por I-146: Fuentes académicas                                            |
| COR-12 | Retirado en I-122: el avance de la carrera se consulta en el Panel |
| COR-13 | Malla, constructor visual/formulario y materias de la carrera |
| COR-14 | Materias, paralelos y docentes del período en curso (pestañas Materias y Docentes, I-171) |
| COR-15 | Integrada en COR-14 como pestaña **Docentes** (I-171); su ruta redirige a COR-14 |
| COR-16 | Proyecto de carrera: documentos y datos curriculares confirmados |

En COR-14, cuando la malla del período ya está programada, una sola acción **Gestionar
docente** reúne **Crear docente** y **Asignar docente** (I-171). Crear abre el formulario compartido de cuentas en modo docente de la carrera
activa (I-54): solo solicita nombre y correo; la persona recibe un enlace de activación
para verificar su correo y definir su contraseña. El rol y la carrera se derivan del
contexto y no se muestran como campos. Un correo existente se incorpora sin
cambiar su identidad ni contraseña. Asignar vincula una cuenta docente existente con un
paralelo. Son operaciones separadas y ninguna levanta los bloqueos académicos.

## Bibliotecario (I-165)

| ID     | Pantalla                                   |
| ------ | ------------------------------------------ |
| BIB-01 | Panel de biblioteca                        |
| BIB-02 | Revisiones de biblioteca                   |
| BIB-03 | Revisión de bibliografía (firmar o comentar) |

El bibliotecario no tiene carrera: entra directo, como Administración, y su tarjeta de
ámbito dice «Biblioteca · toda la universidad». El menú lateral tiene «Panel» y
«Revisiones de biblioteca». BIB-01 muestra «Por verificar», «Con comentario» y
«Firmados», sin plazo ni Puesta en marcha. BIB-02 es la bandeja de sílabos enviados, con
búsqueda y filtro de estado («Por verificar», «Firmado», «Con comentario», «Anulado») y
la acción «Abrir» en el menú de tres puntos. BIB-03 muestra la Bibliografía de la
revisión en solo lectura, la tarjeta de firma electrónica (tercera firma) y, mientras
espera verificación, la tarjeta «Enviar comentario» con confirmación. Abajo muestra el
historial de comentarios. El aviso `biblioteca.enviado` abre BIB-03 y
`biblioteca.comentado` abre el sílabo del docente.

En DOC-10, después de las firmas de docente y coordinación, aparece «Enviar a
biblioteca» con confirmación y luego «Enviado a biblioteca el … por …». Los estados de
firma nuevos son «Por enviar a biblioteca» y «Pendiente biblioteca». En DOC-08, cuando
biblioteca comenta, solo la Bibliografía es editable, aparece la tarjeta «Comentario de
biblioteca» con su historial y el botón pasa a «Reenviar bibliografía». Su diálogo
explica que no vuelve a revisión de coordinación y que docente y coordinación firman un
PDF nuevo antes de volver a biblioteca. En ADM-02, el rol Bibliotecario no pide carrera
y puede agregarse a una cuenta que no sea administradora. En ADM-06, el menú Insertar
ofrece el grupo «Firma» (del docente, de coordinación y de biblioteca), con ficha
violeta. En la hoja, la vista previa y las revisiones se ve como un espacio con línea y
la etiqueta debajo; en el PDF, cada sello se dibuja sobre esa etiqueta.

## Administrador

| ID     | Pantalla                                                   |
| ------ | ---------------------------------------------------------- |
| ADM-01 | Panel administrativo                                       |
| ADM-02 | Usuarios                                                   |
| ADM-03 | Detalle, roles y vigencia                                  |
| ADM-04 | Jerarquía de facultades/carreras y catálogos globales      |
| ADM-05 | Plantillas                                                 |
| ADM-06 | Constructor de plantilla                                   |
| ADM-07 | Previsualizar y publicar                                   |
| ADM-09 | Procesos (correos, documentos y análisis en segundo plano) |
| ADM-10 | Auditoría                                                  |
| ADM-11 | Configuración                                              |
| ADM-12 | Convocatorias (proceso institucional de sílabos)           |

## Patrones comunes

- El menú depende del rol activo y de las capacidades efectivas.
- UI-02 recibe a Coordinación con cards de sus carreras vigentes. Para Docencia, las
  cards representan únicamente una asignación de rol por carrera, nunca una materia ni
  un paralelo; al elegirla se abre directamente DOC-02 **Mis sílabos**. Coordinación y
  Docencia eligen siempre, aunque tengan una sola asignación vigente (I-121); solo
  Administración entra directo. UI-02 no muestra el menú lateral: su cabecera ofrece el
  logo, la campana de avisos, el tema y el menú de usuario con «Cerrar sesión». Cada aviso
  indica el ámbito en que se abrirá («Docencia · Software») y al abrirlo el servidor
  activa ese rol antes de navegar; «Ver todas las notificaciones» aparece recién con un
  ámbito activo. Los avisos solo informativos (cuenta activada, plazo extendido, proceso o
  convocatoria en pausa, proceso cerrado) no llevan enlace ni botón «Abrir» (I-163); los
  que piden actuar (sílabos por elaborar, corrección, aprobación, firma, reanudación,
  nuevo rol, coordinación asignada) abren la pantalla donde se actúa. La carrera activa se muestra bajo el nombre de usuario y puede
  cambiarse desde un `Sheet` en ese mismo menú.
- El selector de tema del encabezado alterna únicamente entre claro y oscuro; no existe
  la opción «Sistema». Sin preferencia guardada, la aplicación arranca en claro.
- La identidad visual usa el azul `#2d4b6f` y el rojo `#ac231e` proporcionados por el
  responsable del producto el 2026-10-04. El azul marca acciones primarias, foco e ítem
  activo del menú; el rojo aparece solo como barra del ítem activo. Fondos y sidebar
  se mantienen neutros para que la interfaz no se vea recargada.
  Éxito, advertencia y acción destructiva mantienen su significado propio mediante
  colores semánticos, texto e iconos. El papel y los colores de plantillas y sílabos
  conservan su paleta independiente. No se presenta esta selección como una guía
  oficial de identidad de la UEB.
- El menú lateral de escritorio conserva su estado expandido o colapsado en la cookie
  `sidebar_state` del navegador durante siete días. Lo restaura al recargar o montar
  de nuevo el layout; sin preferencia guardada, aparece expandido.
- UI-03 se abre desde el acceso de **Notificaciones** situado junto al selector de tema en
  el encabezado autenticado común. Por solicitud del responsable del producto del
  2026-10-04, el acceso indica avisos sin leer mediante un punto rojo pequeño, sin número,
  y comunica ese estado en su nombre accesible. No ocupa una entrada del menú lateral.
  El tema visual también se cambia desde ese encabezado; Configuración solo conserva
  Perfil y Seguridad.
- Por solicitud del responsable del producto del 2026-10-04, el menú de usuario se
  traslada del sidebar al extremo derecho del encabezado autenticado común, después de
  la campana de notificaciones y el selector de tema. En escritorio muestra avatar
  circular, nombre y ámbito con texto truncado; en móvil, el acceso muestra solo el avatar
  con el nombre accesible «Menú de usuario». El menú abierto muestra el nombre completo,
  que se ajusta a varias líneas cuando hace falta. Conserva Configuración, cambio de
  carrera o rol cuando corresponde y Cerrar sesión, que usa la variante destructiva roja.
- Todo módulo autenticado presenta el mismo encabezado mediante `PageFrame`: icono,
  título principal único, descripción y espaciado responsive. Regreso, estado y acciones
  ocupan posiciones estables; Configuración comparte el encabezado en su layout y trata
  Perfil y Seguridad como subsecciones.
- Las cards y los resúmenes métricos independientes se reservan exclusivamente para la
  pantalla Dashboard de cada rol. Fuera del Dashboard no se presentan bloques de
  conteos, totales, promedios o porcentajes; un valor solo permanece cuando forma parte
  del contexto operativo de un registro, una tabla o el contenido que se está editando.
- Los dashboards priorizan tareas vencidas, bloqueos y próximos pasos; evitan métricas
  decorativas.
- Listados de volumen variable usan URL para filtros, orden y paginación.
- Toda barra de consulta ordena sus controles como búsqueda, filtros y acción de aplicar;
  en móvil se apilan sin distribuir campos en extremos inconexos.
- Toda tabla muestra el mismo pie con rango, total, página actual y navegación, incluso
  cuando el resultado ocupa una sola página.
- Toda tabla diferencia visualmente el encabezado y alterna el fondo de sus registros;
  el primer registro usa la superficie base y el segundo el tono alterno.
- Toda columna **Acciones** muestra únicamente el botón de tres puntos de
  `TableActionsMenu`, incluso cuando solo existe una opción o el registro es de solo
  lectura. Al abrirlo, el menú nombra el registro para tecnologías asistivas, agrupa las
  opciones aplicables y explica mediante una opción deshabilitada cuando no existe una
  acción disponible; nunca se muestran botones, enlaces o texto de acción directamente
  en la celda.
- En pantallas de gestión con listados, el formulario de alta no ocupa la vista inicial:
  una acción principal abre un panel lateral desde la derecha, conserva los errores en el
  panel y lo cierra únicamente después de una respuesta exitosa.
- Todo dato obligatorio muestra un asterisco rojo junto a su etiqueta y anuncia
  «obligatorio» a tecnologías asistivas. Las condiciones como «si es heredado» o «salvo
  administrador» se indican con la misma condición y no convierten campos opcionales en
  obligatorios.
- La ayuda breve que aparece al pasar el puntero o enfocar un control usa `Tooltip` de
  shadcn. Los elementos HTML no usan el atributo `title` como tooltip nativo; cuando un
  control necesita nombre accesible, lo conserva mediante texto visible o `aria-label`.
- Los formularios en panel lateral mantienen **Cancelar** y la acción principal en un pie
  fijo. Solo el contenido central se desplaza, conserva espacio inferior y pasa por debajo
  del pie sin ocultar el último campo.
- Los botones secundarios textuales (`outline` o `secondary`) no llevan iconos de acción;
  su etiqueta basta para comunicar el resultado. El indicador de carga sí permanece. Se
  exceptúan los controles exclusivamente icónicos con nombre accesible y los disparadores
  que funcionan como campos compuestos, como el selector de fecha.
- El editor docente presenta el formulario completo en flujo continuo. Mientras falten
  obligatorios no muestra acciones de validación o envío; al completarlos ofrece
  **Validar sílabo** y, solo para la versión validada sin errores bloqueantes, cambia a
  **Enviar sílabo**. Las recomendaciones de IA permanecen opcionales y no bloquean.
- DOC-04 ubica el único **Asistente IA** en una columna fija a la derecha del formulario
  en escritorio, nunca uno por sección o campo. En pantallas estrechas se conserva dentro
  del flujo, después del formulario. El chat propone contenido y unidades desde el
  contexto académico/curricular fotografiado y el Texto libre del docente (I-156). No presenta
  fuentes ni revisión automática; la edición y el envío continúan disponibles mientras
  el chat procesa o falla. La ruta de detalle docente usa una superficie documental sin sidebar ni
  encabezado autenticado global; conserva únicamente el cambio de tema y el formulario
  mantiene sus propias acciones operativas.
- I-60 separa los dos estados de ADM-06. `/admin/plantilla/{id}` presenta la plantilla
  completa en solo lectura y ofrece **Editar**. `/admin/plantilla/{id}/editar` conserva
  la hoja y añade una cinta contextual: al seleccionar un bloque, campo o tabla muestra
  únicamente sus acciones. El editor de celdas trabaja dentro de la hoja y no encierra
  el documento en un diálogo de pantalla completa. Durante esta edición no se muestran
  el sidebar ni el encabezado administrativo: una sola cabecera fija de ancho completo
  reúne **Volver a la vista**, las acciones contextuales y **Documento**, sin fila ni
  etiqueta adicional de selección, mientras la hoja se desplaza por debajo.
- En una tabla, **Fondo de celda** y **Color de fuente** son controles distintos y se
  aplican a la celda o las celdas seleccionadas.
- ADM-06 presenta el título principal como un bloque raíz único y siempre primero.
  Administración puede seleccionarlo y editar su texto desde la cinta, pero no eliminarlo,
  duplicarlo, moverlo ni agregarle campos.
- ADM-06 muestra hojas carta separadas y numeradas (I-53). El pie derecho muestra
  únicamente el número de página (1, 2, 3…), sin el total. La cantidad se recalcula
  según el contenido de muestra al agregar, retirar, reordenar o renombrar piezas.
  Los títulos acompañan al primer contenido; un bloque con varios campos puede continuar
  entre hojas y cada subtítulo acompaña la primera línea de su campo. Los textos extensos
  se dividen entre líneas visuales. Las tablas pueden ocupar varias hojas, se dividen
  entre grupos de filas que no atraviesen celdas combinadas y repiten su cabecera al
  continuar. Cada hoja conserva los márgenes de 2.5 cm.
  En pantallas pequeñas, la hoja mantiene su ancho y se desplaza dentro de su área.
  Estos saltos no se guardan: cada sílabo puede ocupar más o menos páginas según lo
  que redacte el docente. La muestra no garantiza los mismos cortes que Word o PDF.
  Las hojas se muestran directamente sobre el fondo de la pantalla, sin panel gris;
  los controles de alta aparecen en el punto del documento donde actuarán.
- «Personalizar plantilla» permite ajustar orientación, márgenes y tres tamaños:
  título, bloque y campo. El tamaño de campo se aplica uniformemente a texto,
  variables y campos dentro de cualquier tabla. La fuente Arial, los colores globales,
  las alineaciones y los énfasis institucionales son fijos y no aparecen como controles.
- Al editar tablas en ADM-06, las columnas se ajustan al ancho imprimible; un diseño
  excepcionalmente ancho conserva desplazamiento horizontal dentro de la hoja. El
  selector `@` permite buscar y recorrer todos los datos automáticos del catálogo y
  se sitúa dentro de la ventana cerca de la celda activa.
- La ficha institucional parte del formato oficial y conserva su diseño al mostrarse en
  el constructor. Sus variables muestran datos de malla, programación, paralelos y docentes;
  no son campos de escritura. Discapacidad y formación son campos que completa el
  docente en su lugar dentro de la tabla. Mapa: `docs/product/identificacion-institucional.md`.
  COR-14 concentra sus paralelos dentro de **Materias y paralelos**. Desde las acciones de cada materia,
  **Paralelos y docentes** crea o corrige paralelos con código y jornada y elige el docente
  de cada uno (asignar o retirar); el servidor rechaza códigos repetidos. La pantalla
  muestra solo el período en curso (o el próximo si aún no empieza), sin selector, y la
  pestaña **Materias** lista materia, código, ciclo y cada paralelo con jornada y docente.
  La pestaña **Docentes** lista los docentes de la carrera con sus paralelos del período,
  estado de activación, **Editar docente** y **Reenviar invitación** (I-171).
- Indicadores del Panel (UI-01): Administración ve avance del proceso (% aprobados), días
  para la entrega, carreras sin convocar y sílabos sin iniciar; Coordinación ve avance,
  sílabos por revisar y sin iniciar; Docencia ve sílabos por entregar y por corregir. El
  plazo deja de ser un indicador numérico aislado para Coordinación y Docencia: una tarjeta
  muestra período, inicio, entrega y el estado próxima, en curso, pausada o vencida. Antes
  del inicio cuenta hacia esa fecha y durante la apertura cuenta hacia la entrega. Una pausa
  o un vencimiento se nombra sin mostrar cero días como si el plazo siguiera vigente.
- El Panel de cada rol muestra primero sus indicadores y debajo «Puesta en marcha»:
  barra de progreso y los pasos en orden (Administración: facultades con logo, carreras,
  periodo, cuentas, coordinadores, plantilla, convocatoria institucional; Coordinación:
  malla, materias programadas, paralelos, docentes, datos del Proyecto de carrera,
  convocatoria; Docencia: recibir, abrir, enviar, firmar). I-163 actualiza los textos al
  sistema vigente (logo obligatorio, plantilla con Texto libre y Contenido de la
  plantilla, borrador que se inicia solo, firma electrónica del sílabo aprobado). Coordinación calcula
  los pasos contra el período institucional vigente y solo los completa cuando todas las
  materias programadas tienen paralelos y todos los paralelos tienen docente vigente. Si
  todo está listo, el último paso permite iniciar la convocatoria desde el mismo Panel.
  I-146 retira la selección y el requisito de Fuentes académicas. Una vez abierta, la tarjeta de plazo abre su
  seguimiento. Docencia continúa desde esa tarjeta hacia **Mis sílabos**. Cada paso
  se calcula con datos reales; el siguiente lleva su botón y la tarjeta desaparece al
  completarse. Administración conserva «Panel» en el sidebar; Coordinación y Docencia
  conservan la pantalla y el checklist, pero no tienen esa entrada lateral ni un atajo
  desde la marca del sidebar. Por solicitud del responsable del producto del 2026-10-04,
  el avance de puesta en marcha se muestra en el Panel y se retira su indicador en
  miniatura del encabezado. El checklist se calcula con los datos vigentes y reaparece
  si algo se borra.
- Encabezado del sílabo: logo de la universidad a la izquierda y logo de la facultad de la
  carrera a la derecha, dentro del ancho útil de los márgenes y antes del bloque de título.
  Administración reemplaza el logo institucional desde la hoja «Personalizar plantilla»
  de ADM-06 y es la única que puede cargar o reemplazar el logo de facultad al
  crearla o editarla en ADM-04. El logo es obligatorio al crear la facultad; el formulario
  indica 600 × 180 px como medida recomendada y exige un PNG sin fondo, con transparencia. Al editar,
  solo vuelve a exigirse si el registro anterior no tiene logo. El logo institucional
  conserva su PNG con transparencia y medida fija de 1012 × 190 px. El sistema ajusta
  ambos conservando la proporción y los centra sobre un lienzo transparente. La plantilla
  institucional usa el logo de facultad de muestra; el sílabo real usa el de su carrera.
- Si una sección tiene un solo campo, no lleva subtítulo «n.1»: basta el título de la
  sección, en la hoja, el editor docente, la revisión y el Word. Con varios campos sí
  se numeran.
- DOC-04 separa la captura del formato de impresión. La ficha institucional aparece
  como un resumen académico de solo lectura y sus respuestas docentes usan controles
  normales debajo. Los bloques de texto y listas se completan como formulario; solo
  planificación, evaluación, bibliografía y otras estructuras realmente tabulares
  conservan una cuadrícula, porque filas y columnas forman parte de sus datos. El diseño
  oficial permanece en la plantilla, las revisiones y las exportaciones.
- El editor presenta todas las secciones consecutivamente como un formulario continuo.
  No añade un índice lateral, un selector de sección ni pasos anterior/siguiente. Las
  secciones se separan con encabezado y divisor, no con una tarjeta repetida; `FieldSet`
  y `FieldGroup` agrupan los controles y evitan repetir el nombre del bloque cuando ya
  coincide con el único campo que contiene.
- DOC-04 no presenta acciones «Guardar ahora» por campo. Cada cambio activa el
  autoguardado y el único estado de guardado visible se mantiene en el encabezado.
- ADM-06 no mezcla lectura y edición: la ruta normal nunca monta controles mutables. La
  ruta `/editar` reutiliza los mismos bloques, campos y documentos persistidos.
- I-156 retira las tablas repetibles y la planificación automática: lo que sigue en
  este apartado sobre filas, unidades, totales y semanas describe el comportamiento
  anterior. La planificación y toda tabla o lista del docente se escriben como Texto
  libre; las tablas de Personalizado son fijas.
- (Anterior a I-156) Las tablas repetibles conservaban sus unidades, columnas tipadas y sumas. El diseño
  distingue cabecera fija, datos, datos de unidad y total: se combinan celdas dentro
  de cada grupo, sin atravesar grupos que se repiten distintas cantidades de veces.
  En ADM-06 se configura sobre la tabla: la cinta clasifica la fila seleccionada, activa
  unidades e inserta datos repetibles en la celda sin un formulario estructural separado.
  Al retirar el último dato repetible, la tabla pasa a ser estática y permite conservar
  texto normal sin exigir otra fila de datos.
  Al arrastrar un borde vertical se ajusta esa celda como en Word: el ancho total y las
  demás filas se conservan mediante divisiones lógicas internas.
  Docencia agrega filas/unidades y completa las casillas, sin herramientas de diseño;
  la revisión muestra el diseño y las variables congelados al enviar. En ADM-06 la
  tabla se selecciona sobre la hoja y se modifica con la cinta del modo de edición. El
  color global de cabecera solo alcanza cabeceras reales;
  nunca convierte todas las filas fijas de una ficha compleja en cabeceras.
  En la planificación, el período aporta la cantidad de semanas lectivas; unidad y
  parcial son independientes. La interfaz sugiere la siguiente semana libre, calcula
  totales por unidad y generales, y compara ACD/APE/AA con la malla. Un desajuste se
  guarda como borrador, pero bloquea validar y enviar. Créditos y total de horas son de
  solo lectura; una incoherencia con 48 horas por crédito se reporta como configuración.
  En ADM-06 aún no existe una materia contra la cual comparar, por lo que el resumen no
  se muestra. Aparece únicamente dentro de un sílabo real con contexto de malla.
  I-146 retira COR-11 y su editor de fuentes. Mientras una importación está abierta, sus
  originales PDF/XLSX se descargan de forma privada y autorizada desde su detalle; al
  guardar o descartar se eliminan (I-152).
  I-149 lleva el recorrido de I-145 a COR-16, **Coordinación → Proyecto de carrera**,
  una pantalla propia de la carrera activa; COR-13 ya no muestra el botón flotante
  **Asistente curricular**. La pantalla recibe PDF/XLSX o
  ofrece un recorrido con
  carga, estado del procesamiento y revisión de la propuesta. No pide transcribir
  contenidos ni indicar manualmente la procedencia de cada dato. Las filas muestran
  materia, contenido propuesto, fuente/localizador/extracto y contenido vigente cuando
  existe, distinguiendo incorporaciones y reemplazos. La revisión resume cuántos datos
  están listos y cuántos requieren revisión, agrupa por materia (plegadas salvo las que
  tienen pendientes) y permite mostrar solo lo pendiente. Cada nombre del documento sin
  coincidencia se relaciona una sola vez con su materia de la malla y la elección se
  aplica a todos sus datos, que quedan seleccionados si no tienen otra observación;
  una discrepancia exige además confirmar esa decisión. Los errores de evidencia o estructura mantienen la fila excluida.
  El envío aplica únicamente las filas seleccionadas y conserva la confirmación de
  regeneración cuando hay sílabos afectados. Una falla muestra el error y ofrece
  reintentar; las capacidades deshabilitadas y el bloqueo por convocatoria se explican
  junto a la acción. Cambiar de carrera descarta el estado local anterior.
  I-157: COR-16 es una sola vista, sin pestañas, historial ni etiquetas. Muestra el
  Proyecto de carrera como una sola tabla (I-160: todo dato es de una asignatura): una
  fila por asignatura en orden alfabético y una columna por cada dato que pide la
  plantilla; lo que falta dice «Sin dato» y la procedencia se ve al pasar el cursor. El botón principal de la cabecera, **Importar documentos**, abre el
  paso a paso en un modal (Documentos, Análisis, Revisión, Listo). La importación en
  curso se conserva: el botón pasa a **Continuar importación** y el modal abre en el
  paso donde quedó; dentro se puede reintentar o descartar con confirmación. Al guardar,
  la tabla se actualiza y el siguiente ingreso empieza por los documentos. La carga
  presenta solo archivos nuevos; una indicación adicional se abre solo si se necesita.
  El pie de la revisión mantiene visible la acción de guardar, también en móvil.
  La acción manual «Resultados de aprendizaje» desaparece del menú de cada materia;
  las correcciones nuevas siguen el recorrido de importación y confirmación.
  Ninguna otra pantalla de Coordinación muestra el antiguo botón flotante.
  COR-16 muestra qué datos pide la plantilla antes de importar, cada uno con la sección
  del sílabo donde aparece; sin @ProyectoCarrera en la plantilla explica el motivo y no
  permite enviar documentos (I-151, I-155). El tablero de Coordinación agrega el paso
  «Confirmar los datos del Proyecto de carrera», que enlaza a COR-16.
  La importación se guía en cuatro pasos visibles: Documentos, Análisis, Revisión y
  Listo. La carga deja arriba solo los archivos nuevos; los datos que se
  buscarán y la indicación opcional quedan plegados. La revisión pide primero la
  materia de cada nombre sin coincidencia («Continuar») y después la elección de datos,
  con regreso a las materias. Al guardar, el último paso ofrece ver los datos
  confirmados o iniciar otra importación; el detalle queda plegado. En Revisión y ante
  una falla, «Descartar importación» pide confirmación y elimina la propuesta y sus
  documentos sin cambiar datos confirmados.
- I-158: «Propiedades del campo» solo tiene **Nombre** y **Hoja horizontal**, en todos
  los campos. Se retiran de Propiedades la lista de preguntas, sus nombres, las
  condiciones y las listas curriculares.
- I-159 (preguntas de seguimiento, como Google Forms o HubSpot): la regla se define en la
  pregunta que se responde. Al hacer clic en una ficha «Docente: Sí o No» —o en una casilla
  «X» del formato UEB, que en la hoja se ve como «X ①» con su significado en el aviso— aparece «Si responde [Sí | No], pedir también:» con
  casillas para las fichas «Docente» cercanas, nombradas por el texto que tienen al lado;
  lo que no se marca se pide siempre. Cada casilla «X» habla solo de su respuesta («Si
  marca «Sí», pedir también:»). Cercanas: si la pregunta está en una tabla, las de su
  bloque de filas (filas unidas por celdas combinadas); fuera de tablas, todo el campo. Las fichas «Docente» no tienen menú. La
  pregunta y sus dependientes llevan el mismo número (①, ②…) y se resaltan juntas al pasar
  el cursor. Se guarda con la hoja (atributo `showWhen` de cada dependiente).
- I-156: al crear un campo o una sección solo se elige **Texto libre** (lo escribe el
  docente) o **Personalizado** (hoja vacía que arma Administración). En la
  hoja, Insertar ofrece Tabla, Lista, Dato automático, Docente, Docente: Sí o No y
  Proyecto de carrera; ya no ofrece «Filas que agrega el docente». El docente escribe el
  Texto libre en un editor con barra simple: Negrita, Cursiva, Lista con viñetas, Lista
  numerada e Insertar tabla (con Fila, Columna, Quitar fila, Quitar columna y Quitar
  tabla dentro de una tabla). La revisión y el Word muestran ese texto con su formato.
- I-155: ADM-06 deja de preguntar quién llena el campo. Al crear un campo o una sección
  solo se elige su forma: **Párrafo**, **Lista** (con viñetas) o **Tabla**. Párrafo y
  lista nacen con la hoja vacía; lo escrito sin marca es fijo. Todo campo se edita sobre
  la hoja con «Editar». El menú Insertar muestra solo icono y nombre: **Dato
  automático** (lista de datos del sistema, ficha verde azulada), **Docente** (ficha
  azul), **Docente: Sí o No** (ficha rosa) y **Proyecto de carrera** (ficha ámbar). Las
  fichas muestran ese nombre, sin símbolos; las del docente llevan borde punteado y las
  que llegan llenas, continuo. Las marcas se insertan solo desde Insertar de la cinta: no
  hay atajos de teclado.
  La cinta de un campo seleccionado muestra **Editar** (sobre la hoja), **Propiedades**
  (nombre, hoja horizontal y condiciones) y **Eliminar campo**. La forma se elige al
  crear el campo y no cambia: Propiedades la muestra sin permitir editarla y la API
  rechaza el cambio; para otra forma se elimina el campo y se crea uno nuevo. Al editar un párrafo o
  una lista, la cinta solo ofrece Insertar y deshacer/rehacer; las herramientas de celdas
  aparecen únicamente en campos con tabla.
  Ninguna marca abre un diálogo: su
  nombre interno sale de lo escrito antes en la misma línea o celda, de la primera celda
  de la fila, del nombre del campo o del de la sección, y se ve al pasar el cursor por
  la ficha. Todo @ProyectoCarrera es por asignatura; en una lista se busca como lista.
  En una lista, lo fijo, !Docente y @ProyectoCarrera forman una sola lista. En una fila
  que agrega el docente, !Docente es un dato de esa fila. Un ?Docente puede condicionar
  otros campos desde «Editar campo». En el formulario del docente, un campo con hoja
  propia se llena sobre esa hoja, igual que en el Word, y ?Docente se responde con dos
  botones Sí y No, también dentro de una tabla. Por ahora no se crean preguntas en las
  que el docente elige de una lista; las existentes siguen funcionando.
- I-151 (sustituido en su pregunta por I-155): al crear un campo o una sección, ADM-06 preguntaba «¿Quién llena este campo?»
  sin términos técnicos: **El docente lo escribe** (un párrafo, una lista con viñetas,
  una lista numerada o una tabla que se llena por filas), **El docente elige de una
  lista** (listas de los documentos de carrera) o **Sale sola de los documentos de la
  carrera**. Esta última elige un dato ya declarado o declara uno nuevo con nombre,
  forma (lista o texto) y alcance (cada asignatura o toda la carrera; el alcance
  desaparece en I-160) y crea un campo
  fijo con ese dato automático. «Editar campo» usa la misma pregunta sin la opción
  automática. Sí/No ya no se ofrece para campos nuevos; los existentes se conservan.
- I-154 (sustituido por I-155): el texto fijo era la cuarta respuesta de «¿Quién llena este campo?» («Nadie, es
  un texto fijo»), al crear un campo o una sección. Se retiran la entrada separada
  «Agregar texto fijo» del menú «+» y el botón «Hacer texto fijo» de la cinta de tablas.
  Un campo existente del docente no se convierte en texto fijo; se crea uno nuevo.
- I-153: en «Editar campo», las condiciones se configuran desde la pregunta: «Si en
  «Estudiantes con discapacidad» responden [Sí], pedir también:» con casillas para las
  preguntas de la misma sección; lo no marcado se pide siempre. Un campo con varias
  preguntas del docente (por ejemplo, la ficha de identificación) muestra «Preguntas que
  llena el docente» para cambiar el nombre de cada una, incluida «Formación y
  experiencia».
  Los campos de selección docente pueden obtener opciones de una o varias listas
  curriculares, por ejemplo habilidades de asignatura y transversales. Cada sílabo
  usa su copia de esas opciones, y el docente elige de ella sin escribir el catálogo.
- ADM-02: una cuenta «Pendiente de activación» (correo aún no verificado) tiene en su
  menú **Reenviar invitación** (nuevo enlace de un solo uso al correo actual) y
  **Eliminar** (solo sin actividad; con historia, desactivar). Corregir su correo invalida
  la invitación anterior y emite otra al nuevo correo (I-38, I-88). COR-15 muestra las
  cuentas pendientes dentro de la tabla de docentes y asignaciones, con estado «Pendiente
  de activación» y la misma acción de reenvío para la carrera activa. Cada fila de
  COR-15 tiene además **Editar docente** (I-170): nombre y correo en un panel lateral;
  el correo queda deshabilitado, con explicación, cuando la cuenta ya fue activada.
- Salida de personas (I-39): en ADM-04 Carreras la coordinación no ocupa una columna;
  su menú tiene **Reemplazar coordinador** (o **Asignar coordinador** si no hay): cierra
  nombramiento y rol de quien sale, abre los de quien entra y, si se marca y no le
  queda otro rol, desactiva la cuenta saliente. Administración elige a la persona
  coordinadora sin tipo de designación ni sustento documental. En COR-15, cada asignación
  vigente ofrece **Relevar docente**: la fila identifica a quien sale y la hoja solo pide
  al docente entrante, muestra cuántos paralelos se transferirán y confirma el efecto sobre
  sus sílabos (borrador se descarta, aprobado se reabre, en revisión bloquea). La auditoría
  registra el cambio sin exigir una referencia documental. Desactivar una cuenta
  (ADM-02) se rechaza si tiene sílabos en curso o es la única administración; al
  desactivar se cierran sus asignaciones docentes activas.
- Los estados usan las mismas etiquetas y colores en todo el producto.
- Cuando Administración corrige una plantilla, facultad, carrera o período utilizado por
  el proceso vigente, la interfaz exige que el alcance esté pausado y muestra cuántos
  sílabos reemplazará. La confirmación aclara que también desaparecen revisiones,
  aprobaciones, IA y exportaciones antes de regenerarlos (I-118).
- Los IDs internos y detalles de infraestructura no se muestran.
- ADM-04 usa el submenú **Estructura académica** con rutas independientes para Facultades
  y Carreras; los periodos académicos se administran en ADM-12 (I-172) y la ruta antigua
  de Periodos redirige allí. El formulario de facultad exige elegir uno de los cinco
  campus institucionales y no solicita código; la tabla de facultades muestra su campus.
  Carreras exige el código de carrera y muestra su Facultad, Modalidad y campus heredado.
  El período genera automáticamente su etiqueta con el rango de meses y año; su
  formulario solo solicita las fechas. Las semanas lectivas se calculan contando las de
  lunes a domingo que toca el rango, aunque la primera o la última sea parcial, y no se
  muestran en la tabla.
  La configuración de malla solo edita la cantidad de ciclos. No hay una pantalla o
  catálogo de campus ni un código de facultad o malla (I-107).
- La modalidad base se fija por carrera (obligatoria al crearla; la aprueba el CES) y
  no por programación. No hay catálogo: son las del reglamento (presencial, semipresencial, en
  línea, a distancia). Cualquier materia puede apartarse en COR-13 («Igual que la
  carrera» o una distinta), sin cambiar la modalidad visible de la carrera. COR-14 programa
  materias sin selector de modalidad: hereda la de la materia o, si no hay excepción, la
  de la carrera y la muestra en el listado (I-35).
- COR-14 no programa materias una a una: **Preparar período** (visible solo mientras haya
  materias de la malla sin programar) abre un modal amplio con las materias activas que
  aún no están programadas en el período en curso. Coordinación
  revisa toda la malla pendiente y confirma uno o más paralelos por materia, cada uno con
  su jornada y un docente opcional. La hoja propone paralelos, jornadas y docentes del
  período anterior, si existen; solo propone docentes activos, verificados y con rol
  vigente en la carrera. La propuesta se puede corregir antes de guardar y los paralelos
  sin docente quedan pendientes de asignación. El guardado es atómico y auditado;
  campus viene de la facultad elegida por la carrera y la modalidad se fija al crear la
  carrera. La programación conserva el campus heredado para mantener la fotografía
  histórica del sílabo. Una materia
  ya preparada no reaparece aquí: para cambios posteriores se usa la acción de su
  materia programada.
- COR-14 no ofrece selector de período (I-171). Elige el período en curso; si no existe,
  el próximo más cercano y, finalmente, el último disponible, con el estado derivado de
  sus fechas en `America/Guayaquil`. Los períodos finalizados permanecen como historial:
  sus materias, paralelos, responsables y sílabos se consultan, pero no se preparan, crean,
  editan, deshabilitan, eliminan ni relevan.
- COR-13 usa una única entrada **Malla**. Si existe, la ruta abre directamente la
  vista interactiva de lectura; si no existe, muestra el estado vacío universal y la acción
  para crearla. No presenta pestañas de modo, buscador, filtros, cards, paginación,
  publicación ni número de versión. Materias, campos y relaciones se consultan y mantienen
  dentro de esa malla. COR-14 conserva una única entrada
  **Materias y paralelos**; los paralelos se agregan desde las acciones de cada materia.
- COR-13 abre en vista de lectura y ofrece **Editar malla** en el encabezado. Antes de
  entrar al modo de edición consulta de nuevo el bloqueo de la convocatoria. Dentro de
  ese modo oculta el sidebar y el encabezado global; su propia barra muestra **Configurar
  ciclos**, **Volver a la vista** y el cambio entre tema claro y oscuro. Al salir oculta
  altas, menús y gestos de edición. La malla activa o inactiva sigue siendo editable cuando la
  convocatoria lo permite; eliminar se rechaza cuando existen materias programadas
  o sílabos y explica que debe deshabilitarse. Editar una asignación cambia docente o
  paralelo; la vigencia laboral se gestiona en la cuenta, nunca nombre o correo desde esta
  pantalla.
- COR-13 abre la malla en una página completa con ciclos, tarjetas, totales y relaciones.
  El pad del portátil desplaza el lienzo en ambos ejes con dos dedos, sin cambiar la escala;
  el zoom se conserva en los controles y en el pellizco cuando el navegador lo admite.
  El lienzo permite zoom, desplazamiento, conexión y reubicación, además de crear una
  materia desde el control al final de su ciclo y editarla desde las acciones de su
  tarjeta. Ambos formularios se abren en el diálogo compartido y no cubren las tarjetas.
  Las tarjetas solo se arrastran dentro de su propio ciclo: al mover una, las demás
  muestran el orden resultante antes de soltarla. El desplazamiento se
  limita al ancho del ciclo con más asignaturas y a la altura de los ciclos configurados,
  conservando solo un margen breve alrededor del contenido. En escritorio, la leyenda y
  los totales flotantes inician visibles y se pueden contraer temporalmente; en móvil se
  consultan desde su botón. El lienzo no muestra minimapa; conserva los controles de zoom
  y el desplazamiento. En oscuro, lienzo, ciclos y tarjetas se distinguen por superficie y
  borde, con textos y metadatos legibles. «Configurar ciclos» solo aparece en el modo de
  edición y solo edita la cantidad de ciclos; los campos de la tarjeta (ACD, APE, AA,
  CRED, TOTAL) los fija el reglamento y nacen con la malla. Al crear o editar una materia,
  todos los campos activos son obligatorios, las magnitudes se presentan en una fila
  compacta y **TOTAL** se calcula con la suma de los componentes de horas, sin incluir
  créditos. La unidad de organización curricular se elige de una lista fija (básica,
  profesional, integración curricular) para cada materia. El ciclo no muestra una franja
  de unidad ni calcula una unidad predominante; el orden dentro del ciclo se asigna
  automáticamente cuando no lo determina el constructor. Al soltar una materia, el orden
  de todo el ciclo se guarda junto en una transacción. Una reubicación correcta se guarda
  sin notificación repetitiva; un fallo restaura el orden anterior y explica la acción
  correctiva.
- **Auditoría** abre directamente ADM-10, sin pestañas ni filtros. Presenta un único
  registro paginado con fecha, actividad, responsable y resultado; acción y recurso se
  agrupan en la misma celda y no se muestran metadatos secundarios. ADM-09 conserva su
  ruta independiente para diagnosticar y reintentar trabajos.
- ADM-12 **Convocatorias** (I-172) es la única pantalla de periodos y convocatorias: una
  fila por periodo lectivo con sus fechas de clases, las fechas de elaboración de sílabos
  (o «Sin fechas») y el estado: **Sin iniciar** (periodo sin convocatoria), **Abierto**,
  **Pausado** y **Finalizado**. El botón **Nueva convocatoria** abre un solo `Sheet` sin
  descripción general, con dos bloques y una línea de ayuda bajo cada uno: fechas del
  periodo (la etiqueta se genera sola) y fechas de elaboración; las cuatro son
  obligatorias. Al enviar, un diálogo confirma que, una vez abierta, el periodo y sus
  fechas no se editan y que cualquier cambio exige eliminar la convocatoria y abrir otra
  (los documentos firmados fijan sus bytes). La SPA crea el periodo y abre la
  convocatoria con el `id` devuelto; si falla la apertura, el periodo queda creado y el
  reintento solo abre la convocatoria. El botón queda deshabilitado, con un aviso, mientras
  exista una convocatoria abierta o pausada. Un periodo en Sin iniciar la abre después
  desde la fila (**Abrir convocatoria**), con el período fijado y el nombre y la plantilla
  automáticos, o se elimina; al eliminarlo se borran también las materias programadas,
  paralelos y asignaciones docentes que las coordinaciones hubieran preparado en él (sin
  convocatoria no hay sílabos). Un periodo con convocatoria se elimina desde ella. Las fechas de elaboración siempre empiezan vacías: el
  formulario no copia las fechas lectivas del período (I-113). Administración nunca edita
  un periodo. **Editar** solo
  aparece en pausa; **Pausar**, **Reanudar** y **Cerrar** confirman en un diálogo que
  explica la consecuencia, sin pedir motivo; en la interfaz **Cerrar** se llama
  **Finalizar** y al finalizar el proceso se finalizan también las convocatorias de
  carrera abiertas o pausadas (COR-03 muestra Sin iniciar, Abierta, Pausada, Finalizada,
  sin columna de período porque el nombre ya es el del período). **Prorrogar plazo** solicita
  únicamente una nueva fecha límite de entrega, sin motivo; no permite cambiar el inicio.
  **Eliminar** está siempre disponible (I-172, reemplaza I-117) y confirma que se
  retirarán la convocatoria institucional, sus alcances y todos sus sílabos, incluidos los
  iniciados, aprobados y firmados, con sus revisiones, documentos, firmas y envíos a
  biblioteca; queda en auditoría con el conteo de sílabos borrados.
  El nombre se genera al crear o guardar
  como «Convocatoria {nombre del período}»; el formulario no pide nombre ni muestra una
  etiqueta de plantilla vacía. La plantilla institucional se sigue heredando. El alta exige elegir el período
  académico institucional; el servidor rechaza igualmente un alta forzada sin estructura lista. COR-03 muestra las convocatorias institucionales visibles y
  permite iniciar únicamente el alcance de su carrera desde una abierta; hereda plantilla,
  período y fechas. El mismo inicio está disponible en COR-01 cuando la comprobación
  completa de malla, programación, paralelos, docentes, plantilla y logo indica que la
  carrera está lista; los documentos de ingesta no son un requisito para convocar. COR-02 lista
  los procesos institucionales desde antes de iniciar la carrera, con estado institucional
  y estado de carrera separados; el filtro muestra solo convocatorias iniciadas, pausadas y
  cerradas, y el seguimiento de expedientes se consulta en un `Sheet` lateral dentro de cada convocatoria. No tiene
  aviso ni botón de alta independientes. El menú de tres puntos de cada fila reúne iniciar,
  ver seguimiento, pausar y reanudar, con el mismo diálogo de
  confirmación que ADM-12. ADM-12 no muestra un aviso de edición bloqueada porque desde
  esa misma pantalla se pausa, reanuda o cierra el proceso. COR-04 es solo seguimiento y
  avisa cuando la convocatoria o
  el proceso institucional están en pausa o cerrados. ADM-05,
  ADM-06 y COR-13 muestran un aviso con la razón del bloqueo y ocultan las
  acciones de edición mientras dure; el servidor rechaza igual aunque se fuerce la
  petición. I-44 limita ese candado a la plantilla institucional y, por carrera, a la
  malla, datos curriculares, programaciones, paralelos y asignaciones genéricas. Facultades, carreras,
  períodos, cuentas y roles continúan administrables durante el proceso abierto.
- El menú de acciones de ADM-04 distingue edición y eliminación: **Editar** abre un
  `Sheet` precargado; **Eliminar** pide confirmación y explica si una dependencia o
  historial institucional protege el registro.
- El fondo de página y las superficies de trabajo usan tokens distintos en claro y
  oscuro; tarjetas, paneles laterales y popovers conservan contraste semántico común.

### Cobertura del patrón de altas

| Rol           | Interfaces cubiertas                            | Comportamiento                                                                                                                                                                                                                                 |
| ------------- | ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Administrador | ADM-02, ADM-03, ADM-04, ADM-05, ADM-06 y ADM-12 | Cuentas, roles, catálogos, coordinaciones, campos y procesos de sílabos se crean desde una acción que abre el `Sheet` derecho. La única plantilla institucional se crea como contenedor vacío; el administrador puede aplicar «Formatear» para cargar la estructura oficial o construirla manualmente. |
| Coordinador   | COR-02, COR-06, COR-13, COR-14 y COR-15 | Convocatorias, observaciones, mallas, materias programadas, paralelos y asignaciones docentes usan el mismo patrón. En COR-15, los selectores permiten buscar docentes por nombre o correo y paralelos por materia, período o código. |
| Docente       | DOC-02 a DOC-10                                 | No administra colecciones maestras. Edición, IA, envío y respuestas son flujos académicos de página completa, no formularios de alta.                                                                                                          |
| Bibliotecario | BIB-01 a BIB-03 | No administra colecciones. Firma o comenta desde el detalle de la revisión; no hay formularios de alta. |

Selección de rol, filtros, configuración personal, resolución de contradicciones y
acciones de ciclo de vida como publicar, activar, aprobar o reabrir permanecen en su
de esa regla porque no crean una colección desde un listado. `ManagementCreationUiTest`
protege la clasificación y falla si una alta de gestión vuelve a incrustarse en una
página.
