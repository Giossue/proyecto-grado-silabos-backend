# I-219 — Bloques con significado dentro del documento (propuesta)

Estado: primera entrega hecha (2026-10-10) con los bloques `si_no` y `repetible`, **sin reglas de negocio**.
Las reglas (sumas, topes, obligatoriedad de filas) siguen **POR VALIDAR** y no se programan hasta que la
universidad las confirme (sección «Preguntas»). El bloque `identificacion` también está hecho (ver abajo);
`unidades` sigue siendo la tabla de su plantilla lista.

## Bloque `identificacion` (2026-10-10)

Pedido: en el sílabo de ejemplo la identificación es **una sola tabla** que incluye las filas de
«Estudiantes con discapacidad» y la de «Formación y experiencia». Antes eran tres campos con tres tablas.

- La plantilla oficial pasa de 21 a 19 campos: `asignatura` (docente) contiene el bloque y se eliminan
  `discapacidad` y `formacion_experiencia`.
- El diseño de la tabla vive en `resources/templates/bloque-identificacion.html` (HTML con variables); no lo
  edita la administración.
- Los datos del sistema se resuelven con las variables de siempre. La **formación sale del perfil del
  docente** (`usuarios.formacion_experiencia_usuario`) mediante la variable `formacion_docente`.
- El docente responde solo discapacidad (`value.disability`: Sí/No, tipo y descripción) dentro de la misma
  tabla; marcar No borra el detalle. Sin responder cuenta como vacío.
- La API entrega la tabla ya resuelta en `blocks.identificacion.table` (muestras para Administración, datos
  reales para el docente); las casillas de respuesta quedan como variables `discapacidad_*` que cada pantalla
  dibuja como control o como texto.
- Word, PDF y snapshot reciben una sola tabla porque el bloque se expande en `TemplateDocumentResolver`.

## Lo construido

- Nodo `documentBlock` (no `block`, que choca con el grupo `block` del editor) con `attrs.kind`,
  `attrs.props` y `attrs.value`. Backend: `DocumentBlock` valida tipo, columnas, respuesta y filas.
- El bloque se **expande a párrafos y tabla** en `TemplateDocumentResolver`, así que Word, PDF, snapshot y
  contexto de IA funcionan sin cambios. Sin responder cuenta como vacío (el docente debe responder).
- El docente solo puede responder: `UpdateDraftField` rechaza un valor cuyos bloques no coincidan en tipo y
  propiedades con los de la plantilla.
- JSON de importación y exportación: `<div data-block="si_no" data-props='…' data-value='…'></div>`.
- Frontend: menú **Bloque** en la barra del editor (solo Administración), vista del docente con Sí/No y
  filas, y la misma vista en modo lectura.

## Pedido, 2026-10-10

Definir un estándar en JSON donde cada pieza signifique algo y el HTML sepa dibujarla. Lo mismo para las
preguntas Sí/No y para lo repetible. Los responsables piden tener presentes las reglas de negocio **de la
universidad**, no las del sistema.

## Relación con lo decidido

- ADR-0010 (I-210) quitó los tipos de campo porque eran demasiado complejos. Esta propuesta **no devuelve
  tipos de campo**: el campo sigue siendo un documento libre. Se agregan **bloques** que viven dentro del
  documento, igual que hoy una tabla o una lista.
- I-210 recogió que los totales de la tabla de unidades los escribe Docencia sin validación. Una regla
  nueva solo entra si la universidad la pide; hoy no hay ninguna confirmada.
- Las dos plantillas listas (I-218) pasan a ser dos bloques de este estándar.

## Forma propuesta

Un bloque es un nodo del documento con `type: "block"`, un `kind` y sus `props`. Lo que no es bloque
(texto, listas, tablas) no cambia.

```json
{ "type": "block", "kind": "identificacion", "props": {} }

{ "type": "block", "kind": "unidades",
  "props": { "columnas": ["unidad", "semanas", "ac", "ape", "aa"] } }

{ "type": "block", "kind": "si_no",
  "props": { "pregunta": "¿La asignatura tiene estudiantes con discapacidad?",
             "si": { "pide": ["tipo_discapacidad", "descripcion"] } } }

{ "type": "block", "kind": "repetible",
  "props": { "etiqueta": "Estudiante", "campos": [
     { "clave": "tipo", "etiqueta": "Tipo de discapacidad" },
     { "clave": "descripcion", "etiqueta": "Breve descripción" } ],
     "minimo": 0 } }
```

El valor que llena el docente se guarda aparte del bloque, con la misma clave:
`{ "si_no": "si", "filas": [ { "tipo": "Visual", "descripcion": "…" } ] }`.

## Piezas

1. **Catálogo de bloques** en el backend: una clase por `kind` que valida `props` y valores, y sabe
   pasarlos a Word. Es lo único que se escribe cuando aparece un bloque nuevo.
2. **Registro de dibujo** en el frontend: un componente por `kind` para vista, edición del docente y
   configuración del administrador.
3. **Reglas**: cada bloque puede declarar `reglas` (por ejemplo, suma de horas). Solo se agrega una regla
   cuando la universidad la confirma; sin reglas, el bloque no valida nada.
4. **Snapshot**: guarda el bloque y su valor, como hoy guarda documentos y variables.

## Qué no cambia

Plantilla única, secciones y campos, `quien_llena`, JSON de importación y exportación (el bloque es
un nodo más), snapshot v8 y la regla I-188.

## Preguntas para la universidad

1. ¿Qué reglas de negocio quieren que el sistema haga cumplir? Por ejemplo: horas ACD + APE + AA igual al
   total del período, créditos según horas, puntos de evaluación que suman 10, semanas lectivas.
2. ¿Qué preguntas Sí/No existen en el sílabo y qué cambia cuando la respuesta es Sí?
3. ¿Qué listas son repetibles (discapacidad, bibliografía, unidades) y cuántas filas mínimas o máximas?
4. ¿Esas reglas bloquean el envío o solo avisan?

## Orden sugerido al aprobarse

1. Bloques `identificacion` y `unidades` (sin reglas) migrando las plantillas listas actuales.
2. `si_no` y `repetible`, con el sílabo real como prueba.
3. Reglas, una por una, con su confirmación.

## Hallazgos sobre horas y evaluación (POR VALIDAR)

Fuente: `temp/documentos-carrera/Malla-Software-8-ciclos2 (2).pdf` y el sílabo de ejemplo.

- Siempre hay 4 unidades y 16 semanas; cada unidad tiene su tabla y el docente reparte horas por semana.
- La malla da por asignatura las horas ACD, APE y AA (por ejemplo 32/32/32 = 96 y 2 créditos). Los
  totales por unidad del sílabo de ejemplo (11/10/10, 6/12/7, 4/0/4) no suman esas horas: es el caso
  que el docente debería ver marcado como mal hecho.
- Hay asignaturas con 0 horas en algún componente: Prácticas preprofesionales (SW-P7-037: 0/0/0, 5
  créditos, 240 h) y Prácticas de servicio comunitario (SW-IC8-040: 0/0/0, 2 créditos, 96 h).
- La evaluación del sílabo de ejemplo reparte 10 puntos por parcial: ACD 2.5, APE 2.0, AA 2.0 y
  evaluación parcial 3.5, con ponderación a la mitad (5 puntos por parcial). No está confirmado cómo se
  calculan esos puntos ni qué pasa cuando un componente tiene 0 horas.

Respuestas (responsable del proyecto, a validar con Docencia):

- Las horas de las 4 unidades deben sumar exactamente las de la malla en cada componente, y si no
  coinciden se **prohíbe enviar**.
- Reglamento para la elaboración, implementación, seguimiento y evaluación al sílabo UEB (reformado
  08-04-2025, S.O.004): el art. 3 lo declara «de uso imperativo en todas las asignaturas del Plan de
  estudios» y el art. 7 exige programar los temas según las horas de los componentes del Reglamento de
  Régimen Académico y del proyecto de carrera. No nombra las prácticas; como están en la malla con código
  de asignatura (SW-P7-037, SW-IC8-040), entran por la letra del art. 3. Falta confirmación de Docencia.
- Los puntos de evaluación **sí están en norma**: Reglamento del Sistema de Evaluación Estudiantil UEB,
  art. 11 (rdigital.ueb.edu.ec). Con horas en APE: contacto 2.5, APE 2, autónomo 2, evaluación parcial
  3.5 (ponderación 1.25, 1, 1, 1.75). Con 0 horas en APE: contacto 3.5, autónomo 3, evaluación parcial
  3.5 (ponderación 1.75, 1.5, 1.75). Cada parcial suma 10 puntos y pesa 50 %. La norma no cubre ACD o
  AA en 0.
