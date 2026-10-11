# I-219 — Bloques con significado dentro del documento (propuesta)

Estado: primera entrega hecha (2026-10-10) con los bloques `si_no` y `repetible`, **sin reglas de negocio**.
Las reglas (sumas, topes, obligatoriedad de filas) siguen **POR VALIDAR** y no se programan hasta que la
universidad las confirme (sección «Preguntas»). Los bloques `identificacion` y `unidades` siguen siendo
las tablas de las plantillas listas.

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
