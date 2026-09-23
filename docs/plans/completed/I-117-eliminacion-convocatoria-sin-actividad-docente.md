# I-117: Eliminación de convocatoria institucional sin actividad docente

## Estado

Completado localmente.

## Decisión

Administración puede eliminar una convocatoria institucional cuando ninguno de sus
sílabos ha sido iniciado por un docente. La regla es histórica: además del estado
actual, se consulta el evento inmutable `silabo.borrador_iniciado`, por lo que reiniciar
un expediente no habilita el borrado.

La eliminación retira en una transacción los sílabos sin iniciar, los alcances de
carrera y la convocatoria institucional. El evento de eliminación permanece en
auditoría. No se requiere migración: las relaciones existentes permiten eliminar los
registros sin actividad en el orden de sus dependencias.

## Trazabilidad

- RF-008..016, RF-034..036; RN-005..019; CU-03..06; ADM-12.
- I-117 amplía la administración de I-31, I-46 e I-110 sin alterar la inmutabilidad de
  revisiones ni la evidencia docente.
- Solicitud explícita del responsable del producto del 2026-09-23.

## Implementación prevista

- Backend: caso de uso transaccional, autorización administrativa, ruta `DELETE` y
  capacidad de eliminación en el listado.
- Frontend: acción destructiva con confirmación y explicación de las consecuencias.
- Pruebas: permitir el borrado sin actividad, bloquearlo tras cualquier inicio y cubrir
  la presentación de la acción.
- Documentación: actualizar decisiones, pantalla y trazabilidad.

## Verificación

- [x] `SyllabusProcessTest`: 24 pruebas y 342 aserciones aprobadas; las 3 pruebas
  específicas de eliminación aportan 39 aserciones.
- [x] `SyllabusProcessActions.test.ts`: 2 pruebas aprobadas.
- [x] Contrato OpenAPI, typecheck, ESLint y Pint aprobados.
