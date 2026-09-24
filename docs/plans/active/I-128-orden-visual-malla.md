# I-128 — Alta y orden visual de materias en la malla

## Alcance y trazabilidad

COR-13, RF-008..016, RN-005..008, CU-03 y CP-F estructura, alcance y auditoría.
No depende de un PV abierto. La petición del responsable del producto del 2026-09-23
define la interacción: formulario en diálogo, arrastre solo dentro del ciclo y vista
previa del orden mientras se mueve una materia.

## Entrega

- Separar el alta/edición del nodo y usar el diálogo compartido del sistema.
- Evitar que el control de alta se superponga a las tarjetas.
- Confinar el arrastre al ciclo y desplazar las otras tarjetas en tiempo real.
- Reordenar las posiciones de todo el ciclo en una transacción y rechazar cambios de
  ciclo en la operación visual.
- Verificar tipos, lint y pruebas específicas de interfaz y persistencia.

## Criterios

La vista previa coincide con el orden devuelto por la API. Un fallo restaura el orden
anterior. Crear o editar una materia conserva validaciones y campos actuales. La acción
de alta queda disponible en cada ciclo editable sin cubrir otra tarjeta.

## Verificación local

- `CurriculumCanvas.test.ts`: comprueba la separación del botón, el movimiento visual
  de las otras tarjetas, la apertura del diálogo y el ciclo enviado al guardar.
- `AcademicStructureTest`: comprueba posiciones consecutivas y rechazo de cambio de ciclo.
- Frontend: 46 archivos y 139 pruebas aprobadas; lint, typecheck y build Vite aprobados.
- Backend: `composer verify` aprobado, 329 pruebas y 4.012 aserciones; PHPStan y Pint
  aprobados.
- Pendiente la revisión visual con ratón, táctil y zoom en navegador real (PV-19).
