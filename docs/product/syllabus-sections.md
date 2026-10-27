# Secciones del sílabo

La plantilla oficial (`resources/templates/silabo-oficial.json`) tiene doce secciones.
Administración puede cambiarlas desde ADM-06 sin tocar código; esta tabla describe la
plantilla oficial tal como se instala. El formato definitivo depende de `PV-07`.

| N.º | Sección | Campos | Quién llena | Bloque y reglas |
|---:|---|---|---|---|
| 1 | Identificación institucional | Tabla de identificación | Sistema y docente | `identificacion`: datos del sistema; el docente responde la discapacidad (ver `identificacion-institucional.md`). |
| 2 | Descripción de la asignatura | Descripción | Coordinación (ficha) | — |
| 3 | Objetivos de la asignatura | Objetivo general; objetivos específicos | Coordinación (ficha) | — |
| 4 | Resultados de aprendizaje | Resultados de aprendizaje | Coordinación (ficha) | — |
| 5 | Habilidades blandas | Habilidades blandas | Coordinación (ficha) | — |
| 6 | Distribución y planificación de las unidades curriculares | Unidades | Carrera (nombre y resultado) y Docente (semanas), dentro de la misma tabla | Bloque `unidades`, hoja horizontal. Ver abajo. |
| 7 | Metodología y ambientes de aprendizaje | 7.1 Métodos; 7.2 Técnicas; 7.3 Recursos; 7.4 Herramientas TIC/TAC e IA; 7.5 Ambientes | Docente | — |
| 8 | Evaluación de los aprendizajes | Indicadores por parcial; escala de valoración | Fijo | `evaluacion`: tabla de puntos por parcial. Tiene dos variantes, con o sin horas prácticas (APE). |
| 9 | Perfil de egreso | Resultados de la asignatura frente a los de la carrera | Coordinación (ficha) | — |
| 10 | Conducta y comportamiento ético | Conducta y comportamiento ético | Fijo | — |
| 11 | Bibliografía | Básica; complementaria | Docente | Biblioteca la verifica (`library_sections`). |
| 12 | Revisión y aprobación | Tabla de firmas | Fijo | Variables de firma (docente, coordinación, biblioteca) y fechas de envío y aprobación. |

## Quién llena

- **Docente**: escribe en su sílabo. Todo campo del docente es obligatorio para enviar.
- **Coordinación** («Carrera»): el valor sale de la ficha de la asignatura (COR-16). El
  docente lo ve en solo lectura, salvo que Coordinación active «El docente puede
  editarlo» en ese campo de la ficha. Si la ficha está vacía, el envío se bloquea con el
  aviso «La asignatura no tiene ficha: pídala al coordinador».
- **Fijo** (`institucional`): igual para todos los sílabos; nadie lo edita en el sílabo.

## Unidades (sección 6)

- El sílabo conserva el formato oficial: una tabla por unidad con «Nombre de la
  unidad» y «Resultados de aprendizaje» arriba y las semanas debajo.
- El docente escribe el nombre y el resultado de aprendizaje de cada unidad dentro de
  su tabla y completa las semanas. Desde el 2026-10-27 la ficha ya no los trae.
- Cada semana tiene contenidos, horas ACD, APE y AA, actividades de cada componente y
  evaluación.
- Al guardar: las unidades no pueden sumar más de 16 semanas.
- Al enviar: las cuatro unidades deben tener nombre y resultado, deben sumar exactamente
  16 semanas, y las horas ACD, APE y AA deben ser iguales a las de la asignatura en la
  malla.
- El editor muestra en vivo el avance (por ejemplo, «ACD 30 de 32»).
- La primera versión de ADR-0012 separaba los nombres en otra tabla y cambiaba el
  formato. Se retiró el 2026-10-26 (bloque `planificacion_semanal`).

## Formato y respuestas

En las plantillas protegidas (ADR-0012), Administración conserva la edición completa.
Los otros responsables completan los espacios vacíos; no modifican textos fijos,
encabezados, columnas ni estilos. Una tabla con encabezados y una sola fila vacía
permite añadir filas y quitar la última. Las demás tablas conservan sus filas.
Los rótulos e instrucciones no cuentan como respuesta para completar un campo.

## Otros bloques disponibles

- `si_no`: pregunta Sí/No; si la respuesta es Sí, pide una tabla con columnas que fija
  Administración.
- `repetible`: lista de filas con columnas que fija Administración.

El docente solo llena los datos del bloque; no cambia su tipo ni sus columnas. El detalle
técnico está en `docs/architecture/template-json.md`.

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
