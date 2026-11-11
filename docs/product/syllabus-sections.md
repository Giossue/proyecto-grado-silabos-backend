# Secciones del sílabo

La plantilla oficial (`resources/templates/silabo-oficial.json`) tiene doce secciones.
Administración puede cambiarlas desde ADM-06 sin tocar código; esta tabla describe la
plantilla oficial tal como se instala. El formato definitivo depende de `PV-07`.

| N.º | Sección | Campos | Quién llena | Bloque y reglas |
|---:|---|---|---|---|
| 1 | Identificación institucional | Tabla de identificación | Sistema y docente | `identificacion`: datos del sistema; el docente responde la discapacidad (ver `identificacion-institucional.md`). |
| 2 | Descripción de la asignatura | Descripción | Carrera | — |
| 3 | Objetivos de la asignatura | Objetivo general; objetivos específicos | Carrera | — |
| 4 | Resultados de aprendizaje | Resultados de aprendizaje | Carrera | — |
| 5 | Habilidades blandas | Habilidades blandas | Carrera | — |
| 6 | Distribución y planificación de las unidades curriculares | Unidades | Carrera (el docente llena nombre, resultado y semanas, salvo lo que fije el proyecto de carrera) | Bloque `unidades`, hoja horizontal. Ver abajo. |
| 7 | Metodología y ambientes de aprendizaje | 7.1 Métodos; 7.2 Técnicas; 7.3 Recursos; 7.4 Herramientas TIC/TAC e IA; 7.5 Ambientes | Carrera | — |
| 8 | Evaluación de los aprendizajes | Indicadores por parcial; escala de valoración | Institucional | `evaluacion`: tabla de puntos por parcial. Tiene dos variantes, con o sin horas prácticas (APE). |
| 9 | Perfil de egreso | Resultados de la asignatura frente a los de la carrera | Carrera | — |
| 10 | Conducta y comportamiento ético | Conducta y comportamiento ético | Institucional | — |
| 11 | Bibliografía | Básica; complementaria | Carrera | Biblioteca la verifica (`library_sections`). |
| 12 | Revisión y aprobación | Tabla de firmas | Institucional | Variables de firma (docente, coordinación, biblioteca) y fechas de envío y aprobación. |

## Quién llena

- **Carrera** (`carrera`): Coordinación lo escribe por materia en el proyecto de carrera (COR-16)
  y le llega fijo al docente; el docente llena solo los espacios que le quedan (texto libre,
  número, elegir una o varias). Un campo que Coordinación deja vacío lo escribe el docente entero;
  uno sin espacios es de solo lectura. Para enviar, cada campo con espacios debe tener algo y
  cada respuesta debe estar contestada.
- **Institucional** (`institucional`): igual para todos los sílabos; nadie lo edita en el sílabo.
- Hasta el 2026-11-10 había tres: Docente, Coordinación («Carrera») y Fijo. Las secciones 2 a 5
  y 9 eran de Coordinación; la 1, 6, 7 y 11, del docente.

## Unidades (sección 6)

- El sílabo conserva el formato oficial: una tabla por unidad con «Nombre de la
  unidad» y «Resultados de aprendizaje» arriba y las semanas debajo.
- El docente escribe el nombre y el resultado de aprendizaje de cada unidad dentro de
  su tabla y completa las semanas. Desde el 2026-10-27 el proyecto de carrera ya no los trae.
- Cada semana tiene contenidos, horas ACD, APE y AA, actividades de cada componente y
  evaluación.
- El docente declara sus unidades («Agregar unidad», «Quitar») dentro de los límites que
  fija la definición de la plantilla (no se cambian desde la pantalla): mínimo, máximo y con cuántas
  empieza, para las unidades y para las semanas de cada unidad. La plantilla oficial trae
  de 1 a 8 unidades (empieza con 4) y de 0 a 52 semanas por unidad (empieza con 4). Si
  el mínimo sube, las listas cortas se completan con elementos vacíos.
- Las unidades pendientes se marcan en la lista («Pendiente» y «Falta: …»), y los avances
  que faltan van en ámbar.
- Al guardar: las unidades no pueden sumar más semanas que las de clase del período.
- Al enviar: cada unidad debe tener nombre y resultado, las unidades deben sumar las
  semanas de clase del período, y las horas ACD, APE y AA deben ser iguales a las de la
  asignatura en la malla.
- El editor muestra en vivo el avance (por ejemplo, «ACD 30 de 32»).
- La primera versión de ADR-0012 separaba los nombres en otra tabla y cambiaba el
  formato. Se retiró el 2026-10-26 (bloque `planificacion_semanal`).

## Formato y respuestas

En las plantillas protegidas (ADR-0012), Administración conserva la edición completa.
Los otros responsables completan los espacios vacíos; no modifican textos fijos,
encabezados, columnas ni estilos. Una tabla con encabezados y una sola fila vacía
permite añadir filas y quitar la última. Las demás tablas conservan sus filas.
Los rótulos e instrucciones no cuentan como respuesta para completar un campo.

Un espacio de respuesta puede pedir un tipo, como en un formulario: **Texto libre** (el de
siempre), **Número**, **Elegir una** o **Elegir varias**. Administración lo pone desde la cinta
de la plantilla, para toda la universidad, en campos Carrera. Coordinación los deja por
materia, en la página del proyecto de carrera, con «Espacio para el docente», junto al texto que
escribe, sin cambiar el formato fijo de la plantilla. El docente solo responde:
marca opciones (lo elegido queda fijo y solo se quita) o escribe el número. Para enviar, cada
respuesta debe estar contestada. El documento imprime lo elegido como texto.

El docente solo llena los datos del bloque; no cambia su tipo. El detalle técnico está en
`docs/architecture/template-json.md`.

## Reglas de modelado

- La plantilla decide orden, títulos, orientación, quién llena y contenido inicial, sin
  cambiar el esquema de base.
- Un dato que el sistema ya tiene (malla, paralelo, docente) se inserta como variable;
  no se pide de nuevo.
- La revisión enviada guarda su propia copia de la plantilla. Cambiar la plantilla
  después no altera cómo se ve un sílabo enviado.
- La sección 12 la llenan el flujo de revisión y las firmas; ningún texto editable puede
  simular una aprobación.
- El título y los logos del encabezado son fijos y no son campos.
