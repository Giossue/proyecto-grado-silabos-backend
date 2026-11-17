# Pantallas

Inventario de la SPA (`proyecto-grado-silabos-frontend`, rutas en `src/router.ts`). Los
IDs sirven para la trazabilidad. Las rutas `/panel`, `/notificaciones`,
`/configuracion/*` y `/revisiones/:revision/documentos` redirigen a la ruta del rol
activo.

## Globales

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| UI-01 | Acceso | `/ingresar`, `/recuperar-contrasena`, `/restablecer-contrasena`, `/activar-cuenta`, `/verificar-correo`, `/verificar-dos-factores` | Iniciar sesión, recuperar la contraseña, activar la cuenta, pedir otro enlace de activación si la cuenta sigue sin activar y pasar el segundo factor. |
| UI-02 | Selección de ámbito | `/rol` | Una tarjeta por rol y carrera. Aparece cuando no hay rol activo (ver `roles-and-permissions.md`). |
| UI-03 | Notificaciones | `/{área}/notificaciones` | Solo avisos del período de la convocatoria actual (al abrir una nueva, los anteriores dejan de verse; sin convocatoria, los del período que no ha terminado). Abre en «No leídas»; también «Todas», «Marcar todas como leídas» y «Abrir». También en la campana del encabezado, que abre siempre en «No leídas». Por correo solo llegan tres avisos del docente (convocatoria abierta, corrección solicitada y sílabo aprobado) y, a Coordinación, un resumen diario si tiene sílabos por revisar o firmar. |
| UI-04 | Perfil y seguridad | `/{área}/configuracion/perfil`, `/{área}/configuracion/seguridad` | Cada pestaña separa sus secciones en acordeones (ícono, título y descripción), como Sistema: Perfil (Datos de la cuenta; Formación y experiencia académica para el docente), Seguridad (Contraseña —el cambio se hace en un diálogo que abre «Actualizar contraseña»—, Verificación en dos pasos, Sesiones abiertas) y Notificaciones (Resumen diario, Avisos por correo). Perfil: nombre y correo (solo si la cuenta lo permite) y, para el docente, «Formación y experiencia académica-investigativa». Seguridad: contraseña, «Restablecer por correo» (pide confirmar en un diálogo y envía el enlace al correo de la cuenta aunque haya sesión iniciada, con un aviso emergente «Enlace enviado»; máximo 3 envíos por minuto y 10 por día por correo, y al pasarlo el diálogo dice que se alcanzó el máximo; al cambiar la contraseña con él se cierra la sesión en todos los dispositivos) y segundo factor con códigos de recuperación. «Sesiones abiertas» lista los dispositivos (navegador y sistema, última actividad, «Este dispositivo» en el actual) y, si hay otros, ofrece «Cerrar otras sesiones»: pide la contraseña actual y también saca a los que marcaron «Mantener la sesión iniciada»; no hay cierre de una sola sesión. Desactivar el segundo factor pide la contraseña actual y además un código de la aplicación de autenticación o uno de recuperación (que queda usado); tras 5 códigos incorrectos se espera unos minutos. «Cerrar otras sesiones» (pide la contraseña) también cierra los dispositivos que usaban «Mantener la sesión iniciada»; el dispositivo actual sigue con la sesión abierta (2026-11-03). El contenido de ambas pestañas va sobre una tarjeta centrada, como el resto de pantallas. En ambas pestañas, «Volver» (sobre el título) lleva a la pantalla donde se estaba antes de entrar a Configuración, o al inicio del rol si se abrió directo. Administración tiene además la pestaña «Sistema» (`/admin/configuracion/sistema`, 2026-11-02): Notificaciones (interruptor general de los correos y hora del resumen diario), Asistente de IA (proveedor, dirección, modelo, clave y esperas) y Biblioteca (Koha y su dirección). La clave de la IA nunca se muestra ni en parte: el campo queda vacío («Escriba una clave nueva solo para cambiarla» si hay una guardada), con el botón de papelera «Quitar la clave» dentro del campo (sin botón para ver). «Guardar» se activa solo si la sección cambió. Guardar IA o Biblioteca pide la contraseña actual; cada sección con conexión externa tiene «Probar conexión». Coordinación, Docente y Biblioteca tienen la pestaña «Notificaciones» (`/{área}/configuracion/notificaciones`, 2026-11-02): «Avisos por correo», un interruptor por cada aviso de su rol (Docente: resumen diario de lo que debe elaborar o corregir y el plazo, convocatoria abierta, convocatoria reanudada, corrección solicitada, sílabo devuelto a borrador, sílabo aprobado; Coordinación: resumen diario de lo que debe revisar, bibliografía verificada y convocatoria reanudada por Administración; Biblioteca: resumen diario de lo que debe revisar, sílabo por revisar, sílabo reenviado y convocatoria reanudada por Administración), todos encendidos de inicio; «Guardar» solo con cambios. En el sistema los avisos se ven siempre. |

`{área}` es `admin`, `coordinacion`, `docente` o `biblioteca`. Una ruta inexistente
muestra «Página no encontrada».

## Administrador

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| ADM-01 | Antes de empezar | checklist flotante en todas las pantallas con menú (Driver.js) | Botón flotante de check, rojo y con un latido suave mientras falten pasos, arriba del de ayuda en la esquina inferior derecha; se abre solo la primera vez y se reabre cuando se quiera. Pasos previos a abrir la convocatoria (facultades, carreras, cuentas, coordinadores y plantilla); al pulsar uno, lleva a la pantalla donde se hace. Un check al completarlo. El paso de la plantilla se marca solo cuando la plantilla se puede usar (la misma comprobación que hace el servidor al abrir, reanudar o reabrir); si tiene contenido pero no se puede usar, el paso dice qué le falta, y la lista vuelve a quedar pendiente (rojo y con latido) aunque ya exista una convocatoria. «Nueva convocatoria» queda deshabilitada hasta completarlos. Tras abrirla, el panel dice «La convocatoria ya está abierta». |
| ADM-02 | Usuarios y roles | `/admin/usuarios` | Tabla de cuentas con la columna «Roles» para sus roles vigentes; «Crear cuenta» e «Importar desde Excel». Toda importación por Excel (cuentas, carreras, docentes y malla) admite hasta 500 filas con datos y como máximo 600 filas en total, vacías incluidas, y 64 columnas; el permiso se comprueba antes de abrir el archivo. |
| ADM-03 | Detalle de la cuenta | hoja lateral en ADM-02 | «Editar» muestra el nombre en dos campos, «Nombres» y «Apellidos», igual que «Crear cuenta» y las plantillas Excel; cada uno es obligatorio y solo admite letras, espacios, apóstrofo y guion (hasta 90 caracteres). Un nombre antiguo que no cumpla se puede dejar así. Roles vigentes y finalizados, «Agregar otro rol» (Coordinador: si la carrera ya tiene uno, pide confirmar el reemplazo), «Quitar rol», «Reenviar invitación», «Deshabilitar/Habilitar acceso», «Eliminar cuenta pendiente». |
| ADM-04 | Facultades y carreras | `/admin/estructura-academica` | Al pulsar una fila se abren sus datos en solo lectura, con «Cerrar» y «Editar»; «Editar» habilita los campos, «Guardar cambios» y las acciones de la esquina (Desactivar o Reactivar), y «Cancelar» vuelve a los datos. Pestañas Facultades (campus, logo y bibliotecas de Koha donde buscan sus libros los docentes: casillas con las bibliotecas disponibles por su nombre; en «Editar facultad» es la pestaña «Bibliotecas» y en «Nueva facultad» el segundo paso, tras los datos) y Carreras (modalidad, ciclos, coordinador vigente, importación desde Excel; el coordinador se asigna en ADM-02). Facultades y carreras no se eliminan: «Desactivar» (en rojo) y «Reactivar», con la etiqueta «Inactiva». Se pueden editar siempre, también con una convocatoria abierta. Mientras haya una abierta o en pausa, bajo la barra superior sale el aviso «Hay una convocatoria en curso · Si cambia una facultad o carrera, sus sílabos enviados o aprobados vuelven a Borrador»; si el cambio los afecta, pide confirmar. «Desactivar» sale deshabilitado, con el motivo en un tooltip, mientras el servidor no lo permita: una facultad con carreras activas; una carrera con una convocatoria en curso. Al desactivar una carrera con usuarios, la confirmación dice cuántos se retiran («Se retirarán 1 coordinador y 12 docentes de esta carrera; quien tenga rol en otra carrera lo conserva») y el servidor retira sus roles y los paralelos de sus docentes en el mismo paso; las cuentas se conservan (2026-11-10). El logo de la facultad es un PNG con transparencia de hasta 4000 × 4000 píxeles; es opcional al crearla («Puede cargarlo después, pero Coordinación no podrá abrir la convocatoria sin él») (2026-11-08). |
| ADM-06 | Plantilla | `/admin/plantilla` | Ver y editar la plantilla única. Detalle abajo. |
| ADM-13 | Reportes | `/admin/reportes` | Reporte de una convocatoria (selector arriba; por defecto la más reciente). Cifras: sílabos, aprobados (%), enviados a tiempo (% de primeros envíos hasta la fecha de entrega) y días promedio de revisión (del primer envío a la aprobación). Gráficas: «Avance por carrera» (barras apiladas por estado), «Envíos por día» (primeros envíos y reenvíos) y «Devoluciones» (Coordinación y Biblioteca por carrera; solo si hubo). Tablas: por carrera (total, aprobados, pendientes, devueltos, atrasados, avance) y sílabos pendientes por docente, paginada. «Exportar a Excel»: hojas Resumen, Por carrera, Envíos por día y Pendientes. Sin convocatorias: «Todavía no hay convocatorias». |
| ADM-10 | Auditoría | `/admin/auditoria` | Registro paginado: fecha, actividad y responsable, todo con el mismo tamaño de letra. La actividad dice sobre qué registro fue (p. ej. «Programación Básica · Paralelo B · ANA PÉREZ»; vacío si el registro ya no existe) y qué cambió («Jornada anterior: vespertina · Jornada nueva: matutina»). Filtros de lista, sin búsqueda de texto: período (por sus fechas), rol, carrera, actividad, resultado y tipo de actividad: por defecto «Solo lo importante» (sin inicios de sesión, guardados automáticos, validaciones ni descargas); «Incluir actividad técnica» los muestra. «Exportar a Excel» descarga lo filtrado: hoja «Eventos» por fecha (Fecha y hora, Usuario, Rol, Carrera, Acción, Sobre qué, Detalle, Resultado) y hoja «Resumen» con cuántas veces se hizo cada acción en cada carrera. |
| ADM-12 | Convocatorias | `/admin/convocatorias` | Entrada del Administrador; no tiene Panel. Arriba, avance del proceso, días para la entrega y carreras sin convocar, y, flotante, el checklist «Antes de empezar». Una fila por período. Detalle abajo. |

## Coordinador

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| COR-01 | Antes de empezar | checklist flotante en todas las pantallas con menú (Driver.js) | Botón flotante de check, rojo y con un latido suave mientras falten pasos, arriba del de ayuda en la esquina inferior derecha; se abre solo la primera vez y se reabre cuando se quiera. Pasos previos a abrir la convocatoria de la carrera, en orden: malla, materias del período, docentes, paralelos con su docente y proyecto de carrera (2026-11-08); al pulsar uno, lleva a la pantalla donde se hace. Un check al completarlo. «Abrir convocatoria» queda deshabilitado hasta que todo esté listo; su ayuda emergente dice qué falta. «Todo listo · Ya puede abrir la convocatoria» sale solo cuando de verdad se puede abrir; si no, el panel dice qué falta, y tras abrirla dice «La convocatoria ya está abierta». El banner «Paso N de M» cuenta los mismos pasos que el panel (sin el de abrir, que sale como «Último paso») y ambos se actualizan solos tras cada cambio. |
| COR-04 | Seguimiento | pestaña en COR-05 | Todos los sílabos de la convocatoria con paralelos, docentes y estado; en revisión indica la etapa («En biblioteca», que va primero, o «En coordinación»). La fila de un sílabo ya enviado abre su revisión (COR-06). |
| COR-05 | Revisión | `/coordinacion/revisiones` | Entrada de Coordinación; no tiene Panel. Aviso del plazo bajo la barra superior. Sin indicadores; arriba, las acciones «Abrir», «Pausar» o «Reanudar convocatoria»; flotante, el checklist «Antes de empezar». Pestañas «Cola de revisión» (solo lo que espera la decisión de Coordinación, es decir, lo que Biblioteca ya verificó: asignatura, docente, número de revisión y fecha de envío; sin filtro de estado) y «Seguimiento» (COR-04), donde está todo lo demás: en biblioteca, devueltos y aprobados. |
| COR-06 | Detalle de revisión | `/coordinacion/revisiones/:revision` | Con el menú lateral y el encabezado normales, y «Volver a la cola» sobre el título. Si el sílabo es de una convocatoria finalizada y la franja general no lo dice (por ejemplo, ya hay otra convocatoria), una franja propia dice «Convocatoria finalizada · Este sílabo queda solo para consulta». Junto al título, el estado como insignia y notas en texto («En biblioteca», «Devuelto por biblioteca», «Versión N (anterior) · solo consulta»); debajo, el período y el docente. De lo que hace Biblioteca no ve los comentarios de sus devoluciones. El sílabo se ve como el documento final, con la firma de Biblioteca si ya verificó. Decisiones en la cabecera, con diálogos: «Solicitar corrección» y «Firmar y aprobar» (aprobación final; si Coordinación ya había firmado el sílabo, «Aprobar» sin firmar). Íconos: «Historial» y, mientras el sílabo está en revisión y desde la segunda versión, «Comparar con la versión anterior» (muestra la versión anterior al lado, en la misma pantalla, y marca con «Cambió» en ambas los campos que cambiaron). Con el sílabo aprobado, el ícono «Ver PDF» abre el PDF en una ventana aparte. Reiniciar está en «Más». «Historial» abre COR-11. |
| COR-08 | Solicitar corrección | diálogo en COR-06 | «Comentario para el docente» y «Solicitar corrección»; no se firma. |
| COR-09 | Firmar y aprobar | diálogo en COR-06 | Archivo `.p12`/`.pfx`, clave y confirmar: firma, el sílabo queda aprobado y se genera el PDF. |
| COR-10 | Reiniciar sílabo | «Más» en COR-06 | Única acción para descartar un sílabo, en rojo y separada de «Historial». El diálogo dice qué se borra con números (firmas y, si está aprobado, revisiones y PDF), pide el motivo (lo ve el docente y queda en la auditoría) y, en un sílabo aprobado, escribir el nombre de la materia; no hay cuenta regresiva. |
| COR-11 | Historial de versiones | `/coordinacion/revisiones/:revision/historial` | A pantalla completa, como el historial de versiones de Google Docs. A la izquierda, el documento de la versión elegida; a la derecha, el panel «Historial de versiones» con las versiones de la más nueva a la más vieja, cada una con un título que dice qué pasó («Primer envío», «Corregida tras observaciones», «Aprobada»), agrupados por día, con «Versión actual» marcada y, bajo cada versión, sus correcciones, la verificación de Biblioteca («Biblioteca verificó la bibliografía y envió a Coordinación») y la aprobación («Coordinación aprobó el sílabo»). De las devoluciones de Biblioteca solo «lo devolvió al docente». Los campos que cambiaron desde la versión anterior se marcan solos en el documento (la primera versión no marca nada). Junto a la barra de desplazamiento del documento salen marcas amarillas donde hubo cambios; al pulsar una, el documento baja hasta ese campo. |
| COR-13 | Malla | `/coordinacion/malla` | Lienzo con ciclos, materias y requisitos; «Editar malla» abre el editor a pantalla completa; «Importar desde Excel». |
| COR-14 | Materias y asignaciones | `/coordinacion/materias-asignaciones` | Materias con paralelos, jornada y docente; «Acciones masivas»; un clic abre «Paralelos y docentes de la materia». |
| COR-15 | Docentes | `/coordinacion/docentes` | Docentes de la carrera; «Crear docente», «Importar desde Excel»; la fila abre la ficha «Docente», de solo lectura, con «Quitar de la carrera». |
| COR-16 | Proyecto de carrera | `/coordinacion/proyecto-carrera` | Pestañas «Materias» (proyectos de carrera de las asignaturas e importación mediante plantilla Excel) y «Documentos de apoyo» (COR-17). Detalle abajo. |
| COR-17 | Documentos de apoyo | pestaña en COR-16 (`/coordinacion/proyecto-carrera?tab=documentos`; `/coordinacion/documentos-apoyo` redirige ahí) | Material de la carrera para sus docentes (proyecto de carrera, TIC y TAC, habilidades blandas, etc.). «Subir documento» acepta un PDF, Word o Excel de hasta 20 MB y pide escribir su nombre; un Excel con más de 5.000 celdas con datos se rechaza al subirlo («La hoja de cálculo tiene más de 5.000 celdas con datos…»). Tabla con documento, tamaño y fecha de subida, sin tipo ni quién lo subió: todos se ven como PDF. Pulsar la fila abre el documento en una ventana aparte. «Subir documento» va en la cabecera solo con esta pestaña abierta (con «Materias», «Importar desde Excel»). Botón de papelera en la fila (tooltip «Eliminar»), con confirmación, que borra el documento del servidor; al abrir un documento no aparece indicador de carga junto al nombre. Word y Excel muestran «Preparando vista» hasta que el servidor los pasa a PDF; si no se puede, «No se pudo preparar la vista» y hay que volver a subirlo. No depende de la convocatoria ni muestra el aviso de edición bloqueada. |
| COR-18 | Historial | `/coordinacion/historial` | Se abre con el ícono «Historial» del encabezado de «Revisión» (no está en el menú lateral). Sílabos **aprobados** de la carrera del rol activo de convocatorias anteriores (pasan aquí apenas se abre una nueva, como en DOC-09 y BIB-04), en solo lectura. Agrupados por período, del más reciente al más antiguo; columnas: asignatura, código, paralelos, «Elaborado por» (quien envió la revisión aprobada, aunque ya no esté en el paralelo ni en la carrera) y fecha de aprobación. Búsqueda por asignatura o código y filtro por período. La fila abre COR-06, que entonces dice «Volver al historial»; el ícono «Ver PDF» de cada fila abre el PDF firmado en una ventana aparte. |

COR-06 también ofrece «Reiniciar sílabo» (COR-10) solo con el sílabo aprobado y la convocatoria abierta (no en pausa),
y el botón «Documentos» (DOC-10).

## Docente

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| DOC-02 | Mis sílabos | `/docente/mis-silabos` | Entrada del docente; no tiene Panel ni indicadores. Bajo la barra superior, un aviso con los días que quedan para enviar sus sílabos a revisión y la fecha límite (en rojo cuando venció). Debajo, tarjetas del período vigente, hasta tres por fila, solo de la convocatoria actual y ordenadas por ciclo y luego por asignatura: asignatura, paralelo y estado. Sin sílabos y sin convocatoria abierta dice «Todavía no hay una convocatoria abierta · Cuando Coordinación la abra, aquí verá sus sílabos» (2026-11-08). Botón «Historial». El ícono «Documentos de apoyo» no va aquí: aparece al entrar en una materia (DOC-04). |
| DOC-04 | Sílabo | `/docente/mis-silabos/:syllabus` | Editor a pantalla completa, sin menú lateral. Con la convocatoria finalizada, un sílabo sin enviar se ve como el formulario, con lo escrito y sin poder editarlo; el documento queda para lo ya enviado. En la cabecera, el ícono «Documentos de apoyo» (DOC-11) cuando hay documentos. Detalle abajo. |
| DOC-05 | Validación | dentro de DOC-04 | Campos faltantes y reglas de bloques; el botón de envío explica qué falta. |
| DOC-06 | ~~Asistente de unidades~~ | — | Retirado el 2026-11-05: el docente ya no tiene chat de IA. |
| DOC-07 | Envío | diálogo en DOC-04 | Primer envío: «Firmar y enviar» pide el archivo `.p12`/`.pfx` y la clave. Reenvío tras una corrección: «Enviar», sin firmar. Si el botón está deshabilitado, el motivo se lee junto a él, sin pasar el mouse. Si no cambió nada desde la corrección, la confirmación avisa «No ha cambiado nada desde que pidieron la corrección», pero deja enviar (2026-11-08). |
| DOC-08 | Corrección | aviso en DOC-04 | Solo con el sílabo devuelto: aviso ámbar «Coordinación pidió correcciones» o «Biblioteca pidió correcciones» con el último comentario, autor y fecha. Los motivos de Biblioteca salen como lista, uno por línea (también en el historial de versiones). En revisión o aprobado no se muestran comentarios. Al reenviar, el diálogo «¿Enviar a revisión?» dice que el sílabo pasa a revisión y pide asegurarse de haber hecho los arreglos que pidió Coordinación o Biblioteca, según quién pidió la corrección. El reenvío va a Biblioteca, salvo que lo haya devuelto Coordinación y la bibliografía no haya cambiado: entonces va directo a Coordinación. |
| DOC-09 | Historial | `/docente/historial`, `/docente/historial/:career` | Carreras con sílabos de convocatorias anteriores (pasan aquí apenas se abre una nueva) y, dentro, sus sílabos por período, en solo lectura. |
| DOC-11 | Documentos de apoyo | modal desde el ícono «Documentos de apoyo» de DOC-04 (también la página `/docente/documentos-apoyo`) | El ícono solo aparece si Coordinación compartió documentos de la carrera del rol activo. El modal los lista con nombre, tamaño y fecha, en solo lectura; pulsar uno lo abre en una ventana aparte. Uno que aún se prepara («Preparando vista») o sin vista («No se pudo preparar la vista») no se abre. Al abrir el modal se vuelve a pedir la lista. Botón «Cerrar». |
| DOC-10 | Documentos | `/docente/revisiones/:revision/documentos`, `/coordinacion/revisiones/:revision/documentos` | Ver y descargar el PDF de la revisión aprobada apenas se genera, con los sellos de las tres firmas. Sin Word. «Reintentar» si falló. |

## Bibliotecario

| ID | Pantalla | Ruta | Qué hace |
|---|---|---|---|
| BIB-02 | Revisiones de biblioteca | `/biblioteca/revisiones` | Entrada de Biblioteca; no tiene Panel. El período va en el título («Revisiones de biblioteca - Período 2026-2027»), sin insignia aparte; sin período en curso el título queda solo. Aviso del plazo para revisar bajo la barra superior. Arriba, tres indicadores: en revisión, aprobados y corrección solicitada. Debajo, bandeja de toda la universidad con el período en curso: todo envío llega primero aquí. Un sílabo por fila (su envío más reciente que pasó por biblioteca; un reenvío que va directo a Coordinación con la verificación anterior no cuenta como trabajo nuevo), con las columnas asignatura, carrera, docente, responsable y estado («Sin asignar» si nadie lo ha devuelto aún): búsqueda por asignatura, código, carrera o docente, facultad y estado («En revisión», «Aprobados», «Corrección solicitada»). Cada fila muestra su estado con las mismas palabras que el sílabo: «En revisión», «Aprobado», «Corrección solicitada» (y «Anterior» si el docente ya reenvió una versión más nueva). «Atendido» junta los envíos cuya bibliografía firmó y aprobó biblioteca (y pasaron a Coordinación), los devueltos al docente y los reemplazados por una revisión nueva; el detalle dice cuál de los tres fue. Columnas: carrera, docente y estado. Los bibliotecarios se reparten el trabajo por facultad: cada uno ve la última facultad que eligió. |
| BIB-03 | Revisión de bibliografía | `/biblioteca/revisiones/:revision` | Solo asignatura, carrera, docente y la bibliografía; sin número de revisión ni «Enviado por». Si otro bibliotecario lo verificó o devolvió antes, la pantalla avisa quién lo atiende («Lo atiende Ana, que lo revisó antes») y, al pulsar «Devolver», «Firmar» o «Enviar», pregunta si quiere continuar; no se bloquea. Botones en la cabecera: «Devolver al docente» (motivos) y «Firmar y enviar a coordinación» (o «Enviar a coordinación», sin firmar, si Biblioteca ya firmó el sílabo); no hay ícono para ver el sílabo completo. Con el sílabo aprobado por Coordinación, también en el historial, aparece el ícono «Ver PDF» (con su ayuda emergente) para abrir el sílabo completo en una ventana, desde donde se puede descargar; sirve para las auditorías. |
| BIB-04 | Historial | `/biblioteca/historial` | Sílabos de convocatorias anteriores (pasan aquí apenas se abre una nueva), en solo lectura; se llega desde «Historial» en BIB-02 y tiene «Volver a revisiones» arriba; BIB-03 dice «Volver al historial» y regresa ahí si se llegó desde el historial. Columnas: período, carrera, docente y «Firmado por» (quien firmó la bibliografía; «—» si fue devuelto). Filtros por período, facultad y «Solo los míos» (los que el bibliotecario firmó o devolvió). La fila abre BIB-03. |

## Detalle de pantallas clave

### ADM-06 Plantilla

- Abre en modo lectura: las hojas muestran la plantilla como quedará el sílabo, con los
  datos del sistema vacíos (sin valores de muestra). Arriba: «Opciones avanzadas» y la acción principal «Editar»,
  alineadas con el borde derecho de la primera hoja. La barra respeta su papel,
  orientación y el ancho disponible de pantalla, también durante la edición.
- Junto a «Editar», un botón de ícono «Ver como docente» cambia las hojas por el formulario que
  llenará el docente con la plantilla guardada: pestañas por sección, un desplegable por campo,
  obligatorios con «*» y los de Coordinación o Institucionales con su etiqueta. La identificación
  usa datos de muestra. Aviso «Vista de prueba: así verá el formulario el docente. Lo que escriba
  aquí no se guarda». Mientras está activo no aparece «Editar»; al pulsarlo otra vez vuelven las hojas.
- Un botón de ícono «Historial» abre un panel lateral con las versiones de la plantilla, de la más
  nueva a la más vieja: «Versión N», fecha, quién guardó («Sistema» en la primera) y cuántas secciones
  y campos tenía; la que coincide con la plantilla guardada lleva «Actual». Cada versión tiene «Ver
  cambios» (frases cortas de lo que cambió desde esa versión hasta la actual, o «Igual a la plantilla
  actual») y «Restaurar». «Restaurar» pide confirmar («Se carga en el editor como un borrador. La
  plantilla no cambia hasta que pulse «Guardar»») y abre el editor con las secciones y campos de esa
  versión, su título y sus márgenes, tras el aviso de editar de siempre. No se puede restaurar la versión actual ni con la
  plantilla bloqueada («No se puede restaurar ahora: …»).
- En las plantillas protegidas, los párrafos y celdas vacíos son espacios de respuesta.
  El editor no muestra un aviso sobre esto. No hay controles por celda.
- «Editar» pasa a pantalla completa, sin menú lateral: arriba, «Volver a la vista», el
  título y el cambio de tema. «Volver a la vista» pide confirmar si hay cambios sin
  guardar. Activa la cinta de edición sobre las mismas hojas:
  - formato de texto (negrita, cursiva, subrayado, color del texto, alineación, listas). El
    color del texto solo ofrece negro, azul (`#365F91`, el de los rótulos de las unidades) y
    blanco;
  - ícono «Tabla» con su menú (insertar, filas, columnas, combinar, separar, encabezado, fondo de
    celda, quitar). El fondo de celda solo ofrece los colores de la tabla de
    identificación: «Celeste» (en negrita) y «Azul institucional» (texto blanco y negrita),
    o «Sin fondo»;
  - ícono «Tipo de respuesta» (2026-11-16, vuelve a la cinta): en un campo «Editable» abre el
    mismo cuadro que usa Coordinación («Texto libre», «Elegir una», «Elegir varias», opciones y
    «Más opciones»: «Obligatoria», «Ayuda», «Caracteres máximos», «Cómo se muestra» lista o
    desplegable, «Elegir al menos» y «Como máximo»); en un campo «No editable» queda apagado
    («Tipo de respuesta: no va en campos Institucionales»). Responde quien llena el campo: lo
    puede contestar Coordinación en cada materia o dejarlo para el docente;
  - con un campo elegido: «¿Se puede editar?» («No editable» es Institucional y «Editable» es
    Carrera) (2026-11-11), «Plantilla» (inserta
    Identificación institucional, Distribución y planificación, Bibliografía básica o
    complementaria, Bibliografía verificada por, o Firmas), nombre, subir, bajar y eliminar;
  - con una sección elegida: vertical u horizontal, subir, bajar y eliminar;
  - un ícono con ayuda al pasar el cursor, junto a las demás herramientas: «Opciones
    avanzadas», cuyo menú tiene «Diseño» y «Editar JSON»;
  - «Diseño» (diálogo): márgenes y tamaños de letra;
  - los límites de las listas (unidades, semanas por unidad) no se cambian desde la pantalla
    (2026-11-02): quedan en la definición de la plantilla y solo se tocan por JSON;
  - «Guardar» (también Ctrl+S). Nada se guarda hasta «Guardar».
- En la hoja se agregan secciones y campos («Nueva sección», «Agregar campo»), se editan
  títulos en línea y se cambia el logo de la universidad.
- «Opciones avanzadas → Editar JSON» abre «Plantilla como JSON»: «Descargar», «Cargar archivo»
  y «Aplicar y reemplazar». Si hay errores, no cambia nada y muestra la ruta de cada uno. Los bloques
  solo se agregan por JSON.
- Con la convocatoria abierta, todo queda bloqueado y aparece el aviso de edición
  bloqueada. Si la plantilla cambió durante una pausa, se avisa que al reanudar los
  sílabos se adaptan: se conserva lo escrito y los enviados o aprobados vuelven a borrador.
- Asistente (POR VALIDAR): solo al editar, botón flotante «Asistente» abajo a la derecha.
  Abre un panel de chat donde se pide un cambio en lenguaje natural. La IA trabaja sobre lo
  que hay en el editor, aunque no esté guardado. Cada propuesta lista sus cambios con
  «Aplicar» y «Descartar». «Aplicar» pone los cambios en el editor y los marca «Cambió»
  hasta guardar o salir; se guardan con «Guardar», como cualquier cambio. Si el borrador
  cambió después del pedido, la propuesta queda «Vencida» y no se aplica. Un pedido nuevo
  reemplaza la propuesta pendiente. «Nuevo chat» limpia la conversación.

### ADM-12 Convocatorias

- Columnas: período, clases, elaboración de sílabos y estado («Sin iniciar», «Abierto»,
  «Pausado», «Cerrado»).
- «Nueva convocatoria» pide en dos pasos las fechas de clases (con el aviso «Serían N
  semanas de clase entre las fechas», no editable) y las de elaboración y
  entrega, y abre la convocatoria. Está deshabilitado mientras otra siga abierta o
  pausada.
- Al pulsar una fila se abre un diálogo: sin convocatoria, «Abrir convocatoria» o
  «Eliminar periodo»; con convocatoria, «Pausar», «Reanudar», «Prorrogar plazo»,
  «Finalizar» y «Eliminar»; el botón «Cancelar» cierra el diálogo. «Finalizar» se
  ofrece con la convocatoria abierta o pausada y explica que los sílabos ya no se editan,
  envían, revisan ni firman. Antes de confirmar muestra cuántos sílabos quedan sin
  aprobar, solo los estados que tienen alguno, y su total, con «Lo que no esté aprobado
  quedará solo para consulta»; si no queda ninguno dice «Todos los sílabos están
  aprobados». Pide la frase «finalizar convocatoria» y no deja confirmar sin ella ni sin
  el conteo. Deja el estado «Finalizado» (estado guardado `cerrado`; el filtro dice
  «Finalizados»). Si nadie la finaliza, el sistema la deja «Finalizado» el día después de su
  fecha de entrega. Una finalizada ya no ofrece «Finalizar» ni «Eliminar»; sí ofrece
  «Prorrogar plazo», que la vuelve a abrir. «Eliminar» se ofrece con la convocatoria abierta o pausada, aunque haya
  sílabos enviados o aprobados: explica que se borra todo de verdad, muestra cuántos
  sílabos se borran por estado y el total, y pide el «Mensaje para docentes, coordinación
  y biblioteca» y la frase «eliminar convocatoria»; sin las dos no deja confirmar.
  «Eliminar periodo»
  explica que solo se borran las fechas y las semanas lectivas del período, y que las
  materias, los paralelos y los docentes no se borran. Un período con convocatoria
  muestra el motivo y no deja eliminarlo.
- Si la plantilla cambió durante la pausa, «Reanudar» muestra el conteo por estado y
  pide «Reanudar y adaptar sílabos».
- «Prorrogar plazo» dice «Prórroga 1 de 2» (o «2 de 2») y «Hasta el último día de clases»
  con la fecha; el selector no deja elegir después. Con las 2 prórrogas usadas, el plazo
  ya en el último día de clases, las clases terminadas o, en una finalizada, otra
  convocatoria abierta o pausada, la opción queda desactivada y muestra el motivo
  (`extension_block_reason`). En una finalizada también se desactiva mientras la plantilla no
  se pueda usar: «La plantilla no está lista para usarse. Complétela; «Antes de empezar»
  indica qué falta.» Sobre una finalizada, la prórroga la vuelve a abrir («Plazo
  prorrogado. La convocatoria se volvió a abrir.»); si la plantilla cambió después de
  finalizar, muestra el conteo por estado y pide confirmar para adaptar los sílabos
  (`confirmar_rehacer`). Si lo que cambió mientras estuvo finalizada devuelve sílabos enviados o
  aprobados o rehace o quita sílabos con contenido del docente, muestra cuántos vuelven a borrador
  o a Coordinación y cuántos se rehacen o se quitan, pide escribir
  «aplicar cambios» y reenvía con `confirmar_cambios`; con los dos casos se confirma una sola vez
  con «adaptar sílabos».

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
- COR-15 muestra docente, correo y estado (activa o pendiente). La ficha «Docente» muestra
  nombre y correo sin editarlos, aunque la cuenta esté pendiente (eso es de Administración),
  y «Quitar de la carrera» se deshabilita con la convocatoria de la carrera abierta.

### COR-16 Proyecto de carrera

- Materias agrupadas por ciclo, con su estado: «Completa», «n de m campos» o
  «Incompleta». Filtros por materia, ciclo y estado.
- Un clic en una materia abre su página `/coordinacion/proyecto-carrera/{materia}` (antes era
  una ventana): «Volver al proyecto de carrera» arriba, título «Proyecto de carrera de
  {materia}», las secciones en una lista vertical a la izquierda y los campos Carrera de la
  sección a la derecha, cada uno con el mismo editor de texto (negrita, cursiva, subrayado,
  viñetas y numeración). Arriba a la derecha, el ojo («Vista previa») y «Guardar proyecto de
  carrera». Si sale con cambios sin guardar, pregunta «Hay cambios sin guardar» (2026-11-10).
- En la cinta del editor, «Espacio para el docente» abre un cuadro con «Texto libre»,
  «Elegir una» y «Elegir varias»; si es de elegir, las opciones se escriben ahí mismo y no se
  puede insertar sin ninguna. «Más opciones» fija si es obligatoria, la ayuda y los límites
  (2026-11-16), igual que en la plantilla. Lo que escribe Coordinación le llega fijo al docente; un campo que
  deja vacío lo escribe el docente entero. En campos con formato de la plantilla (texto fijo o
  tablas), cada espacio vacío trae un cuadro de texto y el mismo botón; un espacio armado se ve
  como «Espacio para el docente: {tipo}», con sus opciones y el botón «Quitar el espacio».
- Una respuesta que puso Administración sin opciones sale como «{tipo} (lo definió
  Administración)» y pide escribir las opciones; el campo y su sección muestran «Faltan
  opciones» hasta escribirlas. Es lo único que deja la materia «Incompleta».
- La pestaña «Respuestas del docente» y el menú por espacio ya no existen.
- El ojo alterna con la edición y muestra el sílabo de la materia como lo
  recibirán sus docentes antes de llenarlo: los datos de la malla (carrera, facultad, materia, código,
  horas, créditos, prerrequisitos), lo escrito en el proyecto de carrera (aunque aún no se haya guardado) y
  el resto como en la plantilla. Lo que depende del período o del paralelo (modalidad, campus, paralelo,
  docente) queda vacío.
- «Importar desde Excel», con la misma variante verde de las otras importaciones,
  abre «Importar el proyecto de carrera»:
  1. «Descargar plantilla Excel» genera las materias y los campos Carrera;
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
- Donde la plantilla o Coordinación pidieron un tipo de respuesta, el docente solo responde: un
  cuadro de texto libre o un botón que abre las opciones para marcar. Lo elegido queda como
  etiqueta fija que solo se quita con su X; «Elegir una» admite una y «Elegir varias», una o más.
  Sin opciones escritas dice «No hay opciones para elegir: pídalas al coordinador». Al enviar,
  una respuesta sin contestar avisa ««{campo}» tiene una respuesta sin contestar. Elija una
  opción o escriba la respuesta antes de enviar.» y una sin opciones, ««{campo}» tiene una respuesta
  sin opciones para elegir: pídalas al coordinador.» (2026-11-10).
- Ajustes de la respuesta (2026-11-16): la ayuda que dejó quien armó la respuesta sale debajo,
  junto con «Elija de {n} a {m} opciones», «Elija al menos…» o «Elija como máximo…» y «Opcional»
  cuando no es obligatoria; no deja marcar más opciones que el máximo. Una «Elegir una» marcada
  como desplegable se responde con una lista desplegable y se quita con «Quitar la respuesta». El
  texto libre no pasa de su límite de caracteres. Una respuesta opcional vacía no impide enviar.
- Campos del docente: editables. Campos del proyecto de carrera: solo lectura, salvo los marcados
  como editables («Viene del proyecto de carrera; puede ajustarlo.»). Lo institucional se ve en
  «Vista previa».
- Bloques: unidades y semanas con contador de horas, y discapacidad (los datos aparecen
  solo si responde Sí).
- En revisión o aprobado, la pantalla muestra el documento tal como sale. Las tablas «Revisión y
  aprobación» y «Bibliografía verificada por» (al final de Bibliografía, 2026-11-11) ya llevan el sello y la fecha de cada firma que se hizo (docente al enviar,
  coordinación y biblioteca al decidir); lo que falta sigue con su rótulo.
- «Vista previa» (un ojo, con tooltip) muestra la hoja del sílabo en la pantalla, como en
  Plantilla. Es un solo botón: queda presionado mientras se ve la hoja y, al pulsarlo otra vez, vuelve al formulario. No cambia de ícono ni de lugar. Con el sílabo aprobado, el ícono «Ver PDF» (igual que en Biblioteca y Coordinación) abre
  el PDF directamente en otra pestaña. «Copiar desde paralelo X» trae lo escrito en
  otro paralelo propio.
- «Firmar y enviar» (primer envío) o «Enviar» (reenvío ya firmado) queda deshabilitado
  mientras falten campos («Faltan N campos por completar»).

### Diálogo de firma

Lo usan «Firmar y enviar» (DOC-04), «Firmar y enviar a coordinación» (BIB-03) y «Firmar y
aprobar» (COR-06): archivo `.p12` o `.pfx`, clave y confirmar. La firma se hace en el
navegador sobre la declaración que entrega el servidor; el archivo y la clave no se
envían. Cada persona firma una sola vez por sílabo.

### BIB-03 Revisión de bibliografía

- «Firmar y enviar a coordinación» abre el diálogo de firma; la bibliografía queda verificada y el
  sílabo pasa a Coordinación, que da la aprobación final.
- «Devolver al docente» pide al menos un motivo: libro no disponible, código incorrecto,
  ISBN incorrecto, edición desactualizada, datos incompletos u «Otro motivo». Con «Otro
  motivo» aparece un cuadro obligatorio; su texto se envía junto a los motivos marcados.

## Patrones comunes

- **Menú lateral por rol.** Administrador: Convocatorias, Usuarios y roles,
  Facultades y carreras, Plantilla, Auditoría, Reportes. Coordinador:
  Revisión, Materias y asignaciones, Docentes, Proyecto de carrera, Malla. Docente y
  Bibliotecario no tienen menú lateral: el logo va a la izquierda del encabezado y es
  decorativo (no es un enlace). Se reactiva quitando el rol de `ROLES_WITHOUT_SIDEBAR`
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
  - Docente: «Tiene un sílabo por elaborar» o «Tiene N sílabos por elaborar», «Plazo extendido»,
    «Corrección solicitada», «Sílabo aprobado» (cuando el PDF está listo), «Su sílabo volvió a
    borrador» y «Su sílabo volvió a empezar» (reinicio, eliminación, cambio de la base o de la
    plantilla).
  - Coordinación de la carrera: «Bibliografía verificada por Biblioteca» (el sílabo espera su
    aprobación).
  - Biblioteca: «Sílabo enviado» (primer envío, o reenvío tras una corrección de Coordinación
    que cambió la bibliografía) y «Sílabo corregido para revisar» (el reenvío tras su corrección).
  - Administración: «No se pudieron generar los documentos».
  - El título del aviso es el asunto del correo; el mensaje no lo repite y solo dice qué sílabo
    y revisión, el motivo, la fecha límite y qué hacer (2026-11-03).
- **Accesibilidad.** Botón flotante en escritorio (en móvil, en el menú de usuario):
  tamaño del texto, alto contraste, sin animaciones y texto más
  espaciado. Se guarda en la cuenta.
- **Ayuda de pantalla.** Botón flotante fijo «Ayuda de esta pantalla», justo arriba del de
  accesibilidad (esquina inferior derecha), cuando la pantalla tiene texto en
  `ScreenHelpCatalog` (backend). Al pulsarlo empieza una guía con Driver.js: un primer cuadro
  con el texto de la pantalla y después un paso por cada elemento clave, resaltado sobre la
  pantalla real, con «Anterior», «Siguiente», «Listo» y el avance «2 de 5». Los pasos están en
  `screenTours.ts` (frontend); si un elemento no está en pantalla en ese momento, se omite. En
  las pantallas de entrada de cada rol la guía termina con el menú, las notificaciones, el
  tema, la accesibilidad y la cuenta. Sin pasos, muestra solo el texto. En el editor de la
  plantilla, el del sílabo y la revisión de Coordinación el botón va abajo a la izquierda,
  para no tapar «Asistente», y la guía recorre esa pantalla. Perfil, Seguridad y los avisos
  tienen la misma guía en todos los roles. Los textos son cortos y sin términos técnicos, y
  no se editan desde el sistema.
- **Edición bloqueada.** Una franja a todo el ancho, bajo la barra superior, dice
  «Edición bloqueada · Convocatoria {período}» y qué pausar, con «¿Por qué?» e «Ir a
  Convocatorias» («Ir a Revisión» para Coordinación). Convocatorias usa la misma franja
  para «Convocatoria en curso». El servidor rechaza igual la petición.
- **Convocatoria finalizada.** En todos los roles, la franja de plazo pasa a «La convocatoria finalizó» y se queda hasta que Administración abra otra convocatoria. El detalle cambia por rol; Administración ve «Puede reabrirla con una prórroga o abrir una nueva» y, con las clases terminadas, «Puede abrir una nueva». El docente la ve si tuvo sílabos en esa convocatoria. La convocatoria institucional manda sobre la de carrera; con la institucional abierta y la de carrera sin abrir, Coordinación ve «Aún no abre la convocatoria de su carrera». En Facultades y carreras, el aviso de cambios solo sale con una convocatoria en curso (abierta o en pausa).
- **Cambios con una convocatoria finalizada.** Si un cambio habría llegado a los sílabos de una convocatoria finalizada que aún podría reabrirse, al guardar sale el diálogo «La convocatoria ya finalizó» con «La convocatoria {período} ya finalizó. Este cambio no afecta sus sílabos: conservan los datos que tenían. Se aplicará en la próxima convocatoria, o en esta si la reabre con una prórroga. Evite estos cambios hasta abrir la próxima convocatoria, salvo que sea necesario.» (sin punto final, como todo texto de diálogo), «Guardar de todos modos» y «Cancelar». No pide frase. Sale en Facultades y carreras, la malla, el proyecto de carrera (también con Excel), paralelos, asignación de docentes (también en lote, una sola vez por lote) y al reemplazar un coordinador en Usuarios y roles.
- **Aviso de plazo.** Docente, Coordinación y Biblioteca ven en todas sus pantallas, bajo
  la barra superior, una franja ámbar con los días que quedan, qué les toca hacer antes
  del plazo de entrega y la fecha límite. Biblioteca usa el plazo de la convocatoria
  institucional. El editor del sílabo, a pantalla completa, no la muestra. Con el plazo
  vencido la franja sale en rojo: al docente le dice que pida una prórroga a Administración
  y a Coordinación y Biblioteca que solo pueden revisar lo ya enviado.
  En pausa, la franja dice quién pausó solo cuando importa (2026-11-02): al docente,
  «La convocatoria está en pausa · Le avisaremos cuando se reanude para que siga con sus
  sílabos»; a Coordinación y a Biblioteca, si pausó Administración, que fue ella, que las revisiones
  se retoman al reanudar y que les avisaremos (en pausa nadie aprueba, firma ni devuelve), y si la pausó Coordinación, «La convocatoria de su carrera está en pausa ·
  Puede reanudarla desde Revisión». El docente ve la pausa aunque todavía no tenga sílabos
  en la convocatoria (2026-11-11). Administración solo ve la franja cuando ella pausó la
  convocatoria institucional: «Puede reanudarla desde Convocatorias». Con la convocatoria cerrada, la franja dice que los sílabos quedan solo para consulta.
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

- ADM-05 (Plantillas) y ADM-07 (Publicar): hay una sola plantilla, sin publicación; tiene historial de versiones.
- ADM-09 (Trabajos fallidos): sin pantalla.
- ADM-11 (Configuración): es UI-04.
- COR-02 y COR-03 (Convocatorias de carrera): están en COR-01 y COR-05.
- Al firmar, igual que el docente al firmar y enviar: Coordinación («Firmar y aprobar» y «Solicitar corrección») vuelve sola a la cola de Revisión y Biblioteca («Firmar y enviar a coordinación» y «Devolver al docente») vuelve sola a sus revisiones, con el aviso de lo hecho. No se quedan en el detalle.
- COR-07 (Comparar revisiones): la comparación está en COR-06, al lado de la versión anterior. Los campos que cambiaron se resaltan con «Cambió» solo en la versión actual y, como en el historial, llevan una marca amarilla en el borde derecho de la pantalla, debajo del encabezado; pulsarla lleva al campo. El botón «Resaltar cambios» del encabezado quita o vuelve a poner el resaltado y las marcas; queda deshabilitado, con un tooltip, si no hay cambios. Lo mismo en el historial de versiones.
- COR-11 (Fuentes académicas): retirada.
- COR-12 (Informes): el avance está en COR-05.
- DOC-03 (Resumen del sílabo): la vista previa está en DOC-04.
- DEC-01 y DEC-02 (Decanato): el rol no existe.
