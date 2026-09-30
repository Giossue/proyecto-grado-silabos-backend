# Idea: editor de plantillas que sea formulario y documento a la vez

## El problema

Hay que crear documentos oficiales (un sílabo, un informe, un acta) que:

1. **Tienen un formato fijo y exigente**: tablas con celdas unidas, colores, marcas "X", firmas, encabezados. El resultado final debe salir idéntico en Word/PDF.
2. **Los llena otra persona** (el docente), que no debería ver ni tocar el formato, solo responder preguntas.
3. **Tienen lógica**: preguntas Sí/No que muestran u ocultan otras, listas que crecen (unidades), datos que llena el sistema (nombre de la materia, horas, docente).
4. **El formato lo mantiene alguien sin saber programar** (un administrador) y puede cambiar cada semestre.

## Por qué lo que existe no alcanza

| Herramienta | Qué hace bien | Qué le falta |
|---|---|---|
| Creadores de formularios (Form.io, SurveyJS) | Preguntas, tipos, condiciones | No diseñan un documento: tablas simples, sin celdas unidas; el Word hay que programarlo aparte |
| Editores de texto (TipTap, Word en la web) | Diseñar el documento | No entienden "esto es una pregunta" ni condiciones; hay que forzarlos |
| Hojas de cálculo (Handsontable, Univer) | Tablas con celdas unidas | Una celda = un valor; no mezclan texto con preguntas; no son documentos |
| Plantillas Word con marcadores (`${campo}`) | Formato perfecto, lo edita cualquiera en Word | Las condiciones y tipos de pregunta quedan escondidos en texto; difícil para lógica tipo Sí/No |
| Diagramas (Vue Flow) | Ver relaciones y lógica | No hacen documentos |

Cada una resuelve **la mitad**: o el formulario, o el documento.

## Lo que estaría bueno crear

Un editor donde **el documento es el formulario**:

- **Se ve como el documento final** (página, tablas, celdas unidas, colores), como Word.
- **Cada hueco es un bloque con tipo**, que se elige de forma explícita (nunca adivinado del texto):
  - *Texto fijo* · *Pregunta al docente* (texto corto, largo, Sí/No, lista, fecha) · *Dato automático* (del sistema) · *Marca X* (según una respuesta) · *Firma*.
- **Cada pregunta tiene su panel**: nombre, ayuda, obligatoria, y **"¿Cuándo se pide?"** (siempre / solo si otra respuesta es…).
- **Vista de lógica aparte** (aquí sí sirve algo tipo Vue Flow): ver qué pregunta depende de cuál, con flechas.
- **Vista del que llena**: el mismo documento convertido en un formulario limpio, por secciones, sin mostrar el formato.
- **Salida exacta**: Word y PDF generados desde la misma definición.
- **Filas que se repiten**: marcar una fila de tabla como "se repite por cada unidad".
- **Guardado estructurado**: el diseño y las preguntas se guardan por separado (las preguntas en tablas de base de datos, el diseño como documento), sin duplicar nombres ni tipos.

## Cómo se podría construir (idea)

- **Base del documento**: un editor estructurado (TipTap/ProseMirror) con nodos propios para cada tipo de bloque.
- **Tablas**: extensión de tablas con celdas unidas y bloques dentro de las celdas.
- **Panel lateral** para las propiedades de cada bloque seleccionado.
- **Vista de lógica** con Vue Flow, solo de lectura o para editar condiciones.
- **Motor de salida** que recorre la misma definición y arma el `.docx` (PhpWord) y el PDF.

## Qué ya existe en este proyecto

El editor actual del sílabo ya cubre buena parte: TipTap con tablas y celdas unidas, bloques de pregunta/dato automático/marca X/firma, condiciones, formulario del docente y exportación a Word. Lo que faltaría para que sea "la herramienta":

1. Panel de propiedades claro por bloque (nombre, tipo, obligatoria, condición).
2. Vista de lógica (quién depende de quién).
3. Filas que se repiten.
4. Separarlo como librería reutilizable, independiente del sílabo.
