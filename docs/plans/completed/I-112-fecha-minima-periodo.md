# I-112: Fecha mínima al crear un período académico

## Estado

Implementado y verificado localmente el 2026-09-23.

## Decisión

Al crear un período académico, la fecha de inicio debe ser la fecha actual o una
posterior. El día actual se interpreta en `America/Guayaquil`, la zona horaria visible
del producto. La fecha final conserva la regla de ser igual o posterior a la fecha de
inicio.

La restricción aplica al alta. La edición conserva la posibilidad de corregir períodos
actuales o históricos existentes.

## Trazabilidad

- RF-008..016; RN-005..008; CU-03; ADM-04.
- I-41: el período es institucional y único.
- I-108 e I-109: el formulario solo captura el rango y el servidor deriva su etiqueta y
  semanas.
- Solicitud explícita del responsable del producto del 2026-09-23.

## Implementación prevista

- Backend: rechazar `starts_on` anterior al día local actual en el request de creación.
- Frontend: deshabilitar días anteriores a hoy en la fecha inicial y días anteriores a
  la fecha inicial en la fecha final.
- Pruebas: comprobar rechazo del día anterior, aceptación del día actual y límites
  enviados al selector de fecha.
- Documentación: actualizar comportamiento y matriz de trazabilidad.

## Verificación

- [x] `AcademicStructureTest`: 16 pruebas y 82 aserciones.
- [x] `CatalogRecordSheet.test.ts`: 7 pruebas.
- [x] `npm run typecheck`, `npm run lint` y Pint puntual.
