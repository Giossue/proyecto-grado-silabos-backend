# I-148 — Identificación solo desde la plantilla

## Decisión del responsable, 2026-09-28

El sílabo no debe tener bloques fijos en el código; una plantilla editable debe poder
reordenar la ficha de identificación. La cuadrícula fija de `IdentificationCard` ya no
se usa para dibujar documentos, solo para crear la plantilla inicial.

## Alcance y trazabilidad

- I-33, I-34, I-71; RN de plantilla única editable (I-32) y RNF de mantenibilidad.
- La base de pruebas remota tiene 0 revisiones, así que ninguna copia depende del
  lector fijo. Un bloque `institutional` sin documento ya no dibuja una ficha propia.
- No cambia el esquema ni se necesita migración. Las variables y los datos de
  `IdentificationCard::build` se conservan.
- Fuera de alcance: el pie de trazabilidad del Word (decisión pendiente del responsable)
  y el resumen de solo lectura del docente (`SyllabusAcademicContext`, I-71).

## Trabajo

- [x] Retirar del Word la ruta de cuadrícula fija y `WordIdentificationTable`.
- [x] Retirar `identification` (cuadrícula) de las respuestas de docente y revisión.
- [x] Retirar `IdentificationCard.vue` y su tipo en el frontend.
- [x] Ajustar pruebas de Word, revisión y docente; actualizar la documentación.
- [x] Pint, pruebas backend de Documentos/Sílabos/Configuración, typecheck, lint y
      pruebas frontend de las pantallas afectadas.
