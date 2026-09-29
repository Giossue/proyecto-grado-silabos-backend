# I-140 — Plan de contingencia de la plantilla del sílabo

Estado: aprobado y en ejecución (24 de septiembre de 2026). Backend y frontend hechos; falta
la migración remota.

## Por qué

Los errores y textos confusos se iban arreglando uno por uno, a medida que el usuario los
encontraba. La auditoría del 24 de septiembre de 2026 recorrió todas las pantallas como
administración y como docente: hay 109 capturas en `/tmp/auditoria-plantilla/`. Este plan
junta todo lo que falta para cerrar el tema de una vez.

## Reglas fijas (no se vuelven a discutir)

1. En el documento solo el título y los logos del encabezado van fijos en código.
2. Cada parte de la plantilla es de uno de tres tipos: **Texto fijo** (lo escribe
   Administración), **Dato automático** (lo llena el sistema) o **Lo llena el docente**.
3. Todo lo que llena el docente es obligatorio. No hay límites de caracteres ni casilla
   "Obligatorio".
4. Solo se protege contra perder trabajo sin aviso y contra quedar atascado.
5. En pantalla no aparecen palabras internas: heredado, diseño propio, claves `@`/`$`,
   "fila normal", "marca condicional", etc.

## Palabras en pantalla

- **Sección:** hoy se llama "Bloque". Es el título numerado del sílabo.
- **Parte:** hoy se llama "Campo". Es lo que va dentro de una sección.
- **Tipos de parte:** Texto · Lista con viñetas · Lista numerada · Tabla · Sí/No ·
  **Texto fijo (el docente no lo llena)**.
- **Dato automático:** se inserta con `@` o con el botón Insertar, y se muestra con su
  nombre, nunca con su clave.

## Cambios

### Constructor: barra, lienzo y diálogos
1. Quitar la etiqueta "Contenido fijo · el docente no llena".
2. La barra de selección muestra el tipo real, por ejemplo "Texto fijo · Escala de
   valoración" o "Tabla · Planificación".
3. Renombrar Bloque → Sección y Campo → Parte en botones, avisos y mensajes.
4. "Formatear" → "Cargar formato oficial UEB".
5. Hoja horizontal: interruptor "Hoja horizontal" en Editar parte. El lienzo lo respeta,
   y Planificación viene horizontal por defecto.
6. Editar parte:
   - Si el tipo no se puede cambiar, no se muestra el selector.
   - Una parte de texto fijo se edita en el diálogo "Editar texto fijo", que solo pide
     el nombre.
   - Se quita el texto "heredado".
7. Agregar parte y Agregar sección: no se permite un nombre vacío.
8. Quitar el tipo "Selección múltiple". Hoy deja atascado al administrador porque exige
   opciones que no hay dónde escribir.
9. "Agregar texto fijo" pasa a ser una opción propia, fuera de la lista de tipos.
10. Guardar un texto fijo sin documento: mostrar un aviso en vez de no hacer nada.

### Editor de tablas
11. El menú Insertar queda con tres opciones: Dato automático · Pregunta al docente ·
    Filas que agrega el docente. Lo propio de Planificación (unidades, horas, totales)
    viene de fábrica y no se configura.
12. "Hacer texto fijo" pasa a la barra; hoy está escondido en "Filas y columnas".
13. Insertar en una celda agrega después de lo seleccionado, sin reemplazarlo.
14. Escribir `@` en una celda abre la lista de datos automáticos.
15. "Guardar y regenerar" → "Guardar y rehacer los sílabos afectados". El aviso de
    pérdida de datos se mantiene.
16. Las palabras no se parten dentro de las celdas; hoy sale "Ponderació/n".

### Pantalla del docente
17. Al validar no se piden las partes de texto fijo ni los datos automáticos. Hoy pueden
    dejar al docente atascado.
18. Si el problema no lo puede arreglar el docente (dato institucional faltante), el
    mensaje lo dice: "Avise a Coordinación: …". Las horas y semanas de la planificación
    sí las arregla el docente y siguen bloqueando el envío.
19. "Se encontraron 22 error(es) bloqueante(s)" → "Faltan 22 partes por completar", con
    enlace a cada una.
20. El mensaje rojo de una parte desaparece apenas se corrige.
21. Quitar "Complete los campos aplicables.".
22. Las listas y tablas muestran el nombre de la parte, no "Contenido".
23. El panel de IA no dice "El sílabo está listo" mientras falten partes.
24. Los datos automáticos vacíos dicen "Pendiente de Coordinación", sin mostrar claves.
    "Institucional · solo lectura" → "Dato automático".
25. Escribir seguido no pierde letras: el campo no se desactiva mientras se guarda.
26. "Enviar sílabo" pide confirmación: "¿Enviar a Coordinación?".

### Limpieza
27. Borrar el código que no usa ninguna pantalla (`TemplateDesignBlock`,
    `TemplateDocumentEditor`, `TemplateDocumentContextMenu`, `TemplateDocumentTableMenu`,
    `TemplateDocumentTextMenu`), después de pasar su `@` al editor de tablas.
28. Quitar del formulario de Editar parte los datos internos que ya no se usan.

## Qué no se toca

- El guardado automático, el aviso al salir, la detección de conflictos y "Reintentar".
- La validación de horas y semanas contra la malla.
- La firma electrónica (I-139).

## Avance backend (hecho)

- [x] 5. `page_orientation` (`portrait` · `landscape` · `null` = automático) se guarda con
  `PATCH /api/v1/admin/template/{template}/fields/{field}` (partes con docente) o
  `PATCH /api/v1/admin/template/{template}/blocks/{block}/document` (texto fijo). Si no se
  envía, se conserva. Planificación sigue horizontal por defecto; el Word ya lo respeta
  (`SyllabusWordDocument`).
- [x] 8. "Selección múltiple" fuera de `blockTypes` y rechazada al crear (parte o sección);
  una parte existente de ese tipo se puede seguir editando.
- [x] 10. Texto fijo sin `document` o vacío: 422 «Escriba el texto fijo antes de guardar.»
- [x] 17. `ValidateDraft` no exige texto fijo, partes no editables por el docente ni
  definiciones que no están en el documento.
- [x] 18. Dato institucional faltante: «Avise a Coordinación: falta el dato «…» en la
  información de la carrera.» (sigue bloqueando). Horas y semanas sin cambios.
- [x] 3 (backend). Mensajes de la API dicen «sección» y «parte».
- [x] Migraciones `000106`, `000110` y `000111` aplicadas en la base local.

## Cierre y verificación

1. Aplicar las migraciones pendientes en local (`000106`, `000111`).
2. Correr `composer verify`, y en el frontend `vue-tsc`, `eslint`, `vitest` y `build`.
3. Repetir el recorrido de la auditoría con Playwright, como administración y como
   docente, y guardar capturas de antes y después.
4. Respaldar la base remota, migrarla y subir los cambios.

## Parte sin nombre (backend)

- [x] `SaveFieldDefinitionRequest.label`, `SaveTemplateSectionRequest.fields.*.label` y
  `SaveTemplateDocumentRequest.title` opcionales; la sección conserva `title` obligatorio.
- [x] Se guarda cadena vacía (la columna NOT NULL admite `''`; no hay CHECK de no vacío),
  por lo que no hace falta migración.
- [x] Word/PDF: una parte sin nombre no imprime subtítulo ni consume número (6.1, 6.2
  quedan consecutivos).
- [x] Mensajes al docente e IA: `FieldDefinition::displayName()` cae al nombre de la sección.
- [x] Frontend: «Nombre (opcional)» en Editar parte, Agregar parte y Agregar sección (la
  sección sí exige nombre). Sin nombre no hay subtítulo ni número en el lienzo (6.1, 6.2
  consecutivos) ni en la vista del docente.

## Avance frontend (hecho, 24 de septiembre de 2026)

Parte de esto llegó en los commits `ce4682b` y `e4f894f` del frontend.

- [x] 1, 2, 3, 4, 5 (interruptor «Hoja horizontal»; el lienzo agrupa hojas por orientación),
  6, 7 (solo el nombre de la sección es obligatorio), 8, 9, 10 (aviso en vez de nada).
- [x] 11, 12, 13, 14, 15.
- [x] 16. Ajustado a pedido del usuario: las celdas no desbordan. Se usa `hyphens: auto`
  (español) y `overflow-wrap: anywhere` como último recurso, en el lienzo, el editor de
  tablas y las tablas del docente. En columnas muy angostas (Semana, APE) una palabra
  corta puede cortarse.
- [x] 19, 20, 21, 22, 23, 24, 25, 26, 27, 28.
- [x] En la vista previa un dato automático sin valor queda vacío (antes decía «Dato
  automático»).
- [x] Tarjeta «Plazo de la convocatoria»: barra de avance clara bajo las fechas de inicio
  y entrega.
- [x] Arreglado «Editar título» de la barra, que no abría (la referencia quedó dentro de
  un `v-for`).
- [x] `vue-tsc`, `eslint`, `vitest` (167 pruebas) y `build` pasan. Recorrido Playwright
  (administración y docente, escrituras interceptadas) en `/tmp/auditoria-plantilla/despues/`.
- [ ] Pendiente fuera de este plan: Escape no cierra algunos diálogos cuando el cursor está
  en un campo de texto.

## Corrección del borrado de partes y secciones (28 de septiembre de 2026)

- [x] La confirmación global de regeneración se presenta sobre el diálogo de borrado;
  así se puede confirmar la solicitud que el servidor devuelve como `purge_required`.
- [x] El texto del borrado indica que los sílabos en curso afectados pueden rehacerse
  previa confirmación; conserva los expedientes con aprobación y los procesos cerrados
  conforme a I-118/I-144.
- [ ] Comprobar el flujo visual en la pantalla real antes de cerrar la aceptación de
  interfaz de PV-19.
