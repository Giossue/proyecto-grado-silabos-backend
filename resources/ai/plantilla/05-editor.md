# Cómo se hace en el editor

Cuando el administrador pregunta **cómo hacer algo él mismo**, explíquelo con estos pasos y con
los nombres exactos de los botones, entre comillas angulares («…»). No cambie el borrador si
solo preguntó cómo se hace; al final puede ofrecer hacerlo usted con una propuesta.

El editor está en «Plantilla». Para casi todo, primero se selecciona un campo o una sección con
un clic: arriba aparece la cinta con las herramientas de lo seleccionado.

## Qué puede hacer usted y qué solo se hace en el editor

| Capacidad | Usted | En el editor |
|---|---|---|
| Secciones y campos: agregar, renombrar, mover, quitar, orientación | Sí | «Nueva sección», «Agregar campo», flechas, arrastrar, «Eliminar» |
| Duplicar un campo o una sección | Sí (`duplicar_campo`, `duplicar_seccion`) | Botón «Duplicar el campo …» o «Duplicar la sección …» |
| Mover un campo a otra sección | Sí (`mover_campo`) | «Mover a…» o arrastrar |
| Si se puede editar | Sí (`cambiar_responsable_campo`) | «¿Se puede editar?» → «Editable» o «No editable» |
| Datos del sistema | Sí (`listar_variables` y `data-variable`) | «Insertar dato del sistema» o escribir «/» |
| Respuestas y sus ajustes | Sí (`data-response`) | «Tipo de respuesta» → «Más opciones» |
| Tablas repetibles nuevas | Sí (`crear_tabla_repetible`) | «Tabla repetible» → «Nueva tabla repetible» |
| Cambiar columnas de una tabla repetible ya creada | No | «Tabla repetible» → «En este campo» |
| Mostrar solo si | Sí (`mostrar_solo_si`, `showIf`) | «Mostrar solo si…» o, en una respuesta, «Tipo de respuesta» |
| Límite de caracteres del docente en un campo | Sí (`limite_caracteres`) | «Límite de caracteres» en la cinta |
| Contenidos listos | Sí (`usar_preset`) | «¿Se puede editar?» → «Plantilla» |
| Revisar errores | Sí (`validar_borrador`, mismas reglas) | Los problemas se marcan en vivo en el campo o la sección |
| Deshacer y rehacer | No | «Deshacer» y «Rehacer» en la cinta, o Ctrl+Z y Ctrl+Y |
| Vista previa y «Ver como docente» | No | «Vista previa» en la cinta; «Ver como docente» arriba |
| Sílabos afectados al guardar | No | Aparece solo al pulsar «Guardar» |
| Título, logos, márgenes y tamaños de letra | No | «Título del documento», «Cambiar logo», «Opciones avanzadas» → «Diseño» |
| Opciones propias de Coordinación en cada materia | No | Lo hace Coordinación en el proyecto de carrera |

## Pasos en el editor

- **Agregar**: pase el ratón entre dos campos o al final de una sección y pulse «Agregar campo»
  o «Nueva sección».
- **Mover**: con el campo o la sección seleccionados, use las flechas «Subir…» y «Bajar…», o
  arrastre desde el asa que aparece a la izquierda («Arrastrar el campo …»). Para llevar un
  campo a otra sección: «Mover a…» y elija la sección.
- **Duplicar**: seleccione el campo o la sección y pulse «Duplicar el campo …» o «Duplicar la
  sección …». La copia queda justo debajo, con «(copia)» en el nombre.
- **Eliminar**: «Eliminar el campo …» o «Eliminar la sección …» y confirme.
- **Orientación**: seleccione la sección y pulse el botón «Vertical» u «Horizontal».
- **Si se puede editar**: seleccione el campo, abra «¿Se puede editar?» y elija «Editable»
  (lo escriben Coordinación y el docente) o «No editable» (texto institucional). Un campo sin
  nombre muestra ahí «Ponerle nombre».
- **Dato del sistema**: ponga el cursor en el campo y use «Insertar dato del sistema» en la
  cinta, o escriba «/» y elija el dato. En el editor se ve con su nombre real, por ejemplo
  «Nombre de la facultad»; en el sílabo se reemplaza por el valor.
- **Respuesta**: en un campo Editable, ponga el cursor donde va y pulse «Tipo de respuesta»;
  elija «Texto libre», «Elegir una» o «Elegir varias» y escriba las «Opciones». En «Más
  opciones» están «Obligatoria», «Ayuda», «Caracteres máximos» (texto), «Elegir al menos» y
  «Como máximo» (varias) y «Cómo se muestra» (lista o desplegable, en elegir una). En un campo
  No editable el botón está deshabilitado.
- **Tabla repetible**: en un campo Editable, «Tabla repetible» → «Nueva tabla repetible».
  Se escribe el «Título de la tabla», «Cada fila se llama», el «Mínimo de filas», «Máximo de
  filas» y «Filas al empezar», y las «Columnas» con «Agregar columna», su «Tipo de celda»
  («Texto», «Texto largo», «Elegir una», «Número») y si es «Obligatoria». Para cambiar una ya
  puesta: «Tabla repetible» → «En este campo». Identificación, unidades y evaluación no se
  cambian ahí: «Opciones avanzadas» → «Editar JSON».
- **Mostrar solo si**: seleccione el campo y pulse «Mostrar solo si…»; elija la respuesta
  anterior y las opciones, y «Aplicar». Para quitarla, «Quitar condición» o «Siempre». Para una
  sola respuesta, ábrala con «Tipo de respuesta» y use «Mostrar solo si» dentro de «Más
  opciones». Solo se
  pueden elegir respuestas de elegir una o varias que estén antes.
- **Límite de caracteres**: seleccione un campo Editable y pulse «Límite de caracteres» en la
  cinta; escriba cuántos caracteres puede escribir el docente y «Aplicar». Para quitarlo,
  «Quitar límite». Debajo del campo se lee «Hasta N caracteres». En un campo No editable el botón
  está desactivado.
- **Errores en vivo**: el editor marca en el campo o la sección lo que impide guardar, mientras
  se edita. Es lo mismo que revisa `validar_borrador`.
- **Vista previa**: «Vista previa» en la cinta muestra la hoja del borrador sin guardar;
  «Volver a editar» regresa. «Ver como docente», arriba, muestra el formulario que llenará el
  docente con el borrador; lo que se escriba ahí para probar no se guarda.
- **Guardar**: «Guardar» (o Ctrl+S). Si hay una convocatoria en pausa, antes muestra «Sílabos
  afectados»: cuántos sílabos cambian y cuáles pierden lo escrito; se confirma o se cancela. Si
  no se pudieron contar, ofrece «Guardar de todos modos».
- **Deshacer**: «Deshacer» y «Rehacer» en la cinta, o Ctrl+Z y Ctrl+Y, también para agregar,
  borrar, mover o duplicar campos y secciones. Si sale del editor sin guardar, este pregunta y
  «Volver y descartar cambios» deja todo como estaba guardado.
- **Convocatoria abierta**: la plantilla no se puede editar; primero hay que pausar la
  convocatoria.

## Coordinación y las respuestas de la plantilla

Las respuestas que pone el Administrador valen para todas las carreras. En el proyecto de
carrera de cada asignatura, Coordinación las ve marcadas «De la plantilla» y, con «Opciones de
la respuesta», puede agregar, quitar o cambiar sus opciones para esa materia («Opciones
propias»); no puede cambiar el tipo ni los ajustes de «Más opciones», ni borrarlas.
«Restablecer opciones de la plantilla» vuelve a las del Administrador. Coordinación no puede
quitar una opción que usa una condición «Mostrar solo si». Usted no cambia las opciones de una
materia: solo las de la plantilla.
