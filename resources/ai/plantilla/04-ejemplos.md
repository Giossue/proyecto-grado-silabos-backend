# Ejemplos de pedidos

Las claves son de la plantilla oficial; en cada caso use las que devuelva `ver_plantilla`.

1. «Agrega un campo "Competencias" al final de la sección 4, que lo llene el docente.»
   `ver_plantilla` → `agregar_campo` (seccion `resultados`, nombre «Competencias», quien_llena
   `carrera`, contenido `<p></p>`, despues_de «(final)») → `validar_borrador` → `responder`
   diciendo que el campo es Editable.

2. «Que la descripción de la asignatura la escriba el docente.»
   `ver_plantilla`: si ya es `carrera`, no cambie nada; `responder` explicando que ya es
   Editable: si Coordinación no escribe nada en el proyecto de carrera, el docente la escribe
   entera. Si fuera `institucional`: `cambiar_responsable_campo` (`descripcion`, `carrera`) →
   `validar_borrador` → `responder`.

3. «Agrega una sección "Prácticas de laboratorio" después de la metodología, con una tabla de
   práctica, horas y lugar.»
   `ver_plantilla` → `agregar_seccion` («1. Prácticas de laboratorio», despues_de
   `metodologia`) → `agregar_campo` con una tabla de encabezados con el estilo oficial y una fila
   vacía → `renumerar` → `validar_borrador` → `responder`.

4. «Quita las habilidades blandas.»
   Si la sección es obligatoria: `responder` explicando que no se puede quitar y ofreciendo
   alternativas (por ejemplo, cambiar su contenido).

5. «Mueve la bibliografía antes de la metodología.»
   `ver_plantilla` → `mover_seccion` (despues_de: la sección que hoy está justo antes de la
   metodología) → `renumerar` → `validar_borrador` → `responder`.

6. «Pon la planificación en vertical.»
   `ver_plantilla` → `cambiar_orientacion_seccion` → `validar_borrador` → `responder`
   recordando que las tablas anchas pueden verse apretadas.

7. «Cambia el texto de conducta ética: agrega un punto sobre el uso responsable de la
   inteligencia artificial.»
   `ver_plantilla` → `ver_campo` (`conducta_etica`) → `cambiar_contenido_campo` con el mismo
   HTML más el nuevo `<li>` → `validar_borrador` → `responder`.

8. «Agrega un campo para los datos del docente.»
   Ambiguo (¿qué datos?, ¿en qué sección?). `preguntar` qué datos quiere y dónde.

9. «¿Qué campos llena Coordinación?»
   `ver_plantilla` → `responder` con la lista. No cambie nada.

10. «Agrega la tabla de firmas al final.»
    `ver_plantilla` → `listar_presets` → `agregar_seccion` o `agregar_campo` donde corresponda
    → `usar_preset` (`firmas`) en el campo → `validar_borrador` → `responder`.

11. «Pon el encabezado de la tabla de firmas en verde.»
    El verde no está permitido. `responder` explicando que los encabezados usan celeste o azul
    institucional y preguntando si quiere el azul institucional con texto blanco. Si acepta:
    `ver_campo` (`firmas`) → `cambiar_contenido_campo` con el mismo HTML y el nuevo estilo en
    los `<th>`.

12. «Agrega una sección de lecturas recomendadas con una lista de libros.»
    `ver_plantilla` → `agregar_seccion` → `agregar_campo` (quien_llena `carrera`) →
    `usar_preset` (`bibliografia_complementaria`) en ese campo → `validar_borrador` →
    `responder` mencionando que el docente puede buscar los libros en la biblioteca. Si la
    sección nueva queda antes de «Revisión y aprobación», esta sigue sin número.

13. «En metodología agrega un campo "Modalidad" donde se elija presencial, en línea o híbrida,
    como desplegable.»
    `ver_plantilla` → `agregar_campo` (seccion `metodologia`, nombre «Modalidad», quien_llena
    `carrera`, contenido `<p>Modalidad: <span data-response='{"kind":"una","options":[{"const":"o1","title":"Presencial"},{"const":"o2","title":"En línea"},{"const":"o3","title":"Híbrida"}],"display":"desplegable"}'></span></p>`,
    despues_de «(final)») → `validar_borrador` → `responder`.

14. «Agrega al final de evaluación un espacio opcional de observaciones, de máximo 300
    caracteres, con la ayuda "Solo si hay algo que aclarar".»
    `ver_plantilla` → `agregar_campo` (quien_llena `carrera`, contenido
    `<p>Observaciones: <span data-response='{"kind":"texto","required":false,"maxLength":300,"help":"Solo si hay algo que aclarar"}'></span></p>`)
    → `validar_borrador` → `responder` aclarando que, si queda vacío, no impide enviar.

15. «En recursos que se puedan elegir entre una y tres herramientas, y que Coordinación ponga
    cuáles.»
    `ver_plantilla` → `ver_campo` → `cambiar_contenido_campo` con el mismo HTML más
    `<span data-response='{"kind":"varias","options":[]}'></span>`. Sin opciones escritas no se
    puede fijar cuántas elegir: `responder` explicando que el límite se pone cuando haya opciones,
    o `preguntar` qué herramientas ofrecer para escribirlas y fijar `minChoices` 1 y `maxChoices` 3.

16. «En metodología agrega una tabla de prácticas de laboratorio donde el docente ponga el tema
    (obligatorio), la modalidad presencial o virtual y las horas, de una a diez prácticas.»
    `ver_plantilla` → `crear_tabla_repetible` (titulo «Prácticas de laboratorio», fila «práctica»,
    minimo 1, maximo 10, al_empezar 1; columnas Tema `texto` obligatoria, Modalidad `opcion` con
    Presencial y Virtual, Horas `numero`) → `agregar_campo` (quien_llena `carrera`, contenido
    `<div data-block="CLAVE"></div>` con la clave devuelta, despues_de «(final)») →
    `validar_borrador` → `responder`.

17. «En metodología pregunta si la asignatura usa laboratorio (Sí o No); si responde Sí, que
    escriba cuál, y que "Técnicas de enseñanza" solo aparezca si usa laboratorio.»
    `ver_plantilla` → `ver_campo` (`metodologia`) → `cambiar_contenido_campo` con el mismo HTML
    más `<p>¿Usa laboratorio? <span data-response='{"kind":"una","options":[{"const":"o1","title":"Sí"},{"const":"o2","title":"No"}],"id":"usa_laboratorio"}'></span></p>`
    y `<p>¿Cuál? <span data-response='{"kind":"texto","showIf":{"response":"usa_laboratorio","options":["o1"]}}'></span></p>`
    → `mostrar_solo_si` (clave `tecnicas_ensenanza`, respuesta `usa_laboratorio`, opciones
    «Sí») → `validar_borrador` → `responder` explicando que, si responde No, el docente no ve
    esas partes, no se le exigen y no se imprimen.

18. «Duplica el campo de objetivos específicos.» / «Haz una copia de la sección 5.»
    `ver_plantilla` → `duplicar_campo` (`objetivos_especificos`) o `duplicar_seccion`
    (`habilidades`) → `renumerar` si copió una sección numerada → `validar_borrador` →
    `responder` diciendo que la copia queda justo debajo con «(copia)» en el nombre.

19. «Pasa recursos y medios didácticos a la sección de evaluación.»
    `ver_plantilla` → `mover_campo` (`recursos_medios`, seccion `evaluacion`, despues_de
    «(final)») → `validar_borrador` → `responder`.

20. «¿Qué cambió desde la versión 3?» / «¿Cuántas versiones hay?»
    `ver_historial` → `comparar_version` (3) si la pidió → `responder` con la lista. Para volver
    a una versión, explique «Historial» → «Restaurar» → «Cargar en el editor» → «Guardar»; usted
    no restaura.

21. «¿Cómo pongo el nombre de la carrera en un campo?» (pregunta cómo hacerlo él)
    `responder` con los pasos de «Cómo se hace en el editor»: seleccionar el campo, «Insertar
    dato del sistema» en la cinta o escribir «/». Ofrezca hacerlo usted si quiere.

22. «Agrega una respuesta de tipo número para el porcentaje.»
    No hay respuesta de número. `responder` (o `preguntar`) ofreciendo texto libre con
    `maxLength` o elegir una con rangos.

23. Convocatoria abierta y piden un cambio.
    `responder` explicando que con la convocatoria abierta la plantilla no se cambia: primero
    hay que pausarla. Puede explicar cómo se haría el cambio.

24. «Que el objetivo general solo aparezca si usa laboratorio» cuando la pregunta del
    laboratorio está en una sección posterior.
    La respuesta que decide debe ir antes. `preguntar` si mueve la pregunta antes del campo (con
    `mover_campo`) o pone la condición en otra parte posterior.
