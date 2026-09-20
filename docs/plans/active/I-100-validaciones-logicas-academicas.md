# I-100 — Validaciones lógicas de la estructura académica

## Estado

En curso — reglas comprobables implementadas el 20 de septiembre de 2026; pendiente de
confirmar la política de co-docencia (DT-14).

## Trazabilidad

- RF-003..016; RN-001..008 y RN-017..019.
- CU-02, CU-03 y CU-06.
- UI-04, ADM-04, COR-02, COR-13..15.
- CP-F de identidad, alcance, historial, concurrencia e inmutabilidad.

## Resultado demostrable

Una mutación académica no acepta relaciones imposibles o ambiguas solo porque la
interfaz las oculte. El servidor y PostgreSQL deben responder de forma comprensible
cuando se repite una asignación, se usa una carrera ajena, se intenta tocar un período
finalizado, se reactiva una relación histórica o se modifica una relación ya incorporada
a un sílabo. La preparación repetida de un período tampoco duplica materias ni
paralelos, y un relevo no puede producir dos responsabilidades activas para la misma
persona y el mismo paralelo.

## Decisiones y supuestos

- La misma combinación docente–paralelo no puede tener dos asignaciones activas.
- Dos docentes distintos en un mismo paralelo se conservan como comportamiento vigente:
  la apertura genera un sílabo y registra ambos como colaboradores. El producto todavía
  no confirma si esto representa co-docencia válida o si debe limitarse a uno; este plan
  no cambia esa regla sin esa decisión.
- Las asignaciones históricas inactivas deben poder coexistir con una nueva asignación
  posterior del mismo docente al mismo paralelo; de lo contrario un relevo de ida y
  vuelta rompe la operación.
- Una asignación que ya participa en un sílabo solo cambia mediante relevo o transferencia
  explícita, nunca mediante el endpoint genérico de estado.
- Una asignación histórica no se reactiva ni se edita; el relevo crea una nueva fila.
- El docente entrante debe tener cuenta activa, correo verificado y rol docente vigente
  en la carrera. Si ya ocupa uno de los paralelos del relevo, la operación completa se
  rechaza antes de cambiar al docente saliente.

## Cambios previstos

- Dominio: centralizar la validación de identidad docente–paralelo y distinguir relación
  activa de historial.
- Backend: convertir duplicados y reactivaciones inválidas en errores de validación;
  reforzar las mutaciones de asignaciones ya usadas por sílabos.
- Datos: reemplazar la unicidad histórica global por unicidad parcial sobre asignaciones
  activas, conservando las filas inactivas y sus referencias históricas.
- Frontend: no requiere cambio de contrato; mostrará los errores `422` existentes.
- Seguridad/auditoría: mantener alcance por carrera y auditar únicamente mutaciones
  aceptadas.

## Pruebas

- Duplicar el mismo docente en el mismo paralelo devuelve validación y no crea otra fila.
- Un docente puede conservar asignaciones en varios paralelos.
- Un relevo puede volver a asignar al docente anterior sin colisión con su historial.
- Una asignación vinculada a un sílabo no puede desactivarse/reactivarse por estado.
- Dos docentes en un paralelo conservan el comportamiento actual hasta resolver la
  decisión de co-docencia.
- La preparación repetida y los códigos duplicados después de normalizar espacios no
  crean registros parciales.
- Se mantienen las pruebas de alcance, período finalizado, convocatoria abierta y relevo.

## Pasos

- [x] Auditar reglas actuales, relaciones y restricciones de PostgreSQL.
- [x] Añadir validación de duplicado en creación y edición.
- [x] Ajustar la unicidad física para separar activos de históricos.
- [x] Bloquear cambios de estado sobre asignaciones incorporadas a sílabos y reactivación
      o edición de históricos.
- [x] Cubrir los casos con pruebas de integración.
- [x] Revisar y documentar los casos restantes de estructura, convocatoria y ciclo de
      vida.
- [ ] Confirmar si dos docentes distintos pueden compartir un paralelo (DT-14).

## Riesgos y reversión

- Añadir una restricción parcial puede descubrir duplicados activos existentes. La
  migración debe detenerse antes de cambiar el índice si los encuentra.
- No se elimina historial ni se reasignan colaboradores automáticamente. La reversión
  consiste en retirar la migración nueva y restaurar el índice anterior solo si no se han
  creado filas que dependan de la nueva semántica.

## Evidencia de cierre

`php artisan test --filter='(AcademicStructureTest|CareerTeacherTest|TeacherReliefTest|TeacherTransferTest)'`
verifica 44 pruebas y 379 aserciones. La suite completa local pasó 293 pruebas y 3.726
aserciones. La migración `000091` aún no se ha ejecutado en la
base remota; antes de aplicarla debe comprobar que no existan duplicados activos.
