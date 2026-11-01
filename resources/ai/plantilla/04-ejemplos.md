# Ejemplos de pedidos

Las claves son de la plantilla oficial; en cada caso use las que devuelva `ver_plantilla`.

1. «Agrega un campo "Competencias" al final de la sección 4, que lo llene el docente.»
   `ver_plantilla` → `agregar_campo` (seccion `resultados`, nombre «Competencias», quien_llena
   `docente`, contenido `<p></p>`, despues_de «(final)») → `validar_borrador` → `responder`.

2. «Que la descripción de la asignatura la escriba el docente.»
   `ver_plantilla` → `cambiar_responsable_campo` (`descripcion`, `docente`) →
   `validar_borrador` → `responder` avisando que Coordinación deja de llenarla en la ficha.

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
    `ver_plantilla` → `agregar_seccion` → `agregar_campo` (quien_llena `docente`) →
    `usar_preset` (`bibliografia_complementaria`) en ese campo → `validar_borrador` →
    `responder` mencionando que el docente puede buscar los libros en la biblioteca. Si la
    sección nueva queda antes de «Revisión y aprobación», esta sigue sin número.
