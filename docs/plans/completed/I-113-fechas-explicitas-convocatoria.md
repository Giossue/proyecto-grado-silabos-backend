# I-113: Fechas explícitas de la convocatoria institucional

## Estado

Completado localmente.

## Decisión

El período académico se vincula como referencia, pero sus fechas no se copian al
preparar la convocatoria institucional. Administración debe elegir explícitamente el
inicio de elaboración y la fecha límite de entrega.

Las coordinaciones siguen heredando las fechas elegidas por Administración al iniciar
el alcance de su carrera. La edición de una convocatoria existente conserva sus valores
actuales.

## Trazabilidad

- RF-008..016, RF-034..036; RN-005..019; CU-03..06; ADM-04, ADM-12, COR-02..04.
- I-110 mantiene la preparación y apertura atómicas desde el período; I-113 reemplaza
  únicamente la precarga de fechas del período.
- Solicitud explícita del responsable del producto del 2026-09-23.

## Implementación prevista

- Frontend: iniciar vacíos los dos selectores de fecha durante la preparación nueva.
- Backend: conservar las fechas requeridas y sin valores predeterminados.
- Pruebas: cubrir el formulario sin precarga y el rechazo de una preparación sin fechas.
- Documentación: actualizar decisiones, pantalla y trazabilidad.

## Verificación

- [x] Prueba focalizada del formulario Vue: 2 pruebas aprobadas.
- [x] Prueba focalizada de `SyllabusProcessTest`: 21 pruebas aprobadas.
- [x] Typecheck, lint y formato puntual aprobados.
