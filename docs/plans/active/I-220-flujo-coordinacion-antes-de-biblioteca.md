# I-220 — Coordinación revisa primero y Biblioteca aprueba al final

## Decisión del responsable, 2026-10-13

El flujo pasa a ser docente → coordinador → biblioteca.

1. El docente envía el sílabo a Coordinación.
2. Coordinación aprueba si no hay cambios; si los hay, lo devuelve al docente.
3. Al aprobar Coordinación, el sílabo pasa solo a Biblioteca.
4. Biblioteca aprueba la bibliografía y recién entonces el sílabo queda Aprobado y se
   genera el PDF; si encuentra un problema, lo devuelve al docente.
5. Cuando Biblioteca devuelve, el reenvío del docente va directo a Biblioteca y no pasa
   otra vez por Coordinación. Cuando devuelve Coordinación, el reenvío vuelve a Coordinación.

Esta decisión reemplaza el orden Biblioteca → Coordinación de I-173 e I-192. Las firmas
del PDF conservan el orden docente → coordinación → biblioteca. La revisión enviada y la
aprobación siguen inmutables.

## Trazabilidad

RF-017..045; RN-009..027; CU-04..07; UI COR-05..10, BIB-01..03; CP-F revisión,
aprobación y firmas. Sin dependencia de decisiones `POR VALIDAR`.

## Implementación

- La etapa vigente se deriva de los registros: con aprobación no hay etapa; con envío a
  biblioteca la etapa es biblioteca; sin él, coordinación.
- La aprobación de Coordinación se guarda en `revisiones_coordinacion` (inmutable) y crea
  el envío a biblioteca en la misma transacción.
- Al reenviar tras una devolución de Biblioteca (`correccion_etapa = 'biblioteca'`), el
  envío a biblioteca se crea al enviar y no se registra revisión de coordinación.
- La aprobación de Biblioteca registra su verificación, crea la aprobación, deja el
  sílabo Aprobado y solicita el PDF.
- Avisos: el envío avisa a Coordinación; la aprobación de Coordinación y el reenvío
  directo avisan a Biblioteca; la aprobación final avisa al docente.
- No cambia el esquema de base de datos.

## Verificación

Pruebas de flujo, biblioteca y firmas actualizadas; Pint y PHPStan.
