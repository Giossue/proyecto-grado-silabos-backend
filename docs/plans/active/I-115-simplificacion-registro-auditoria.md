# I-115: Simplificación del registro de auditoría

## Estado

Implementado localmente el 2026-09-23.

## Decisión

ADM-10 presenta directamente una sola tabla de actividad. La vista deja de mezclar el
registro con la pestaña de procesos internos y retira los filtros y el detalle de
metadatos para reducir la carga visual.

Cada fila conserva la información necesaria para reconocer el evento: fecha, actividad,
responsable y resultado. La actividad reúne acción y tipo de recurso; el responsable
reúne actor, rol y carrera cuando corresponda. La paginación permanece para no cargar el
historial completo.

ADM-09 conserva su ruta y su responsabilidad operativa independiente; este cambio no
modifica trabajos, reintentos, permisos, persistencia ni el contrato de auditoría.

## Trazabilidad

- RF-073 y RF-074; RN-034; CU-17; ADM-09 y ADM-10.
- I-05 e I-13 sobre auditoría segura y consistencia de listados.
- Solicitud explícita del responsable del producto del 2026-09-23.
- No depende de decisiones `POR VALIDAR`.

## Implementación

- Retirar de ADM-10 las pestañas de navegación y la barra de filtros; presentar ADM-09
  con su propio título cuando se acceda por su ruta.
- Reducir la tabla de seis a cuatro columnas y omitir metadatos secundarios.
- Mantener estados de carga, error, vacío y paginación.
- Actualizar especificación, mapa de pantallas y prueba focalizada de la vista.

## Verificación

- [x] `npm run typecheck`.
- [x] Pruebas focalizadas de `AdminAuditPage` y `LoadingSkeleton`: 21 aprobadas.
- [x] ESLint focalizado sobre los archivos modificados.
- [x] `git diff --check` en ambos repositorios.

La puerta global de lint y tipos quedó condicionada después de la primera comprobación
por `src/zz-repro.test.ts`, un archivo ajeno sin seguimiento que contiene errores de
tipado. La comprobación focalizada del cambio y las pruebas sí están en verde.
