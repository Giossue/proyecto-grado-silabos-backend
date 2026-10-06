# I-192 — El proceso termina en Coordinación

## Decisión del responsable, 2026-10-05

El Decano no participa en el sistema. El docente envía a Biblioteca; Biblioteca verifica
la bibliografía y pasa a Coordinación; el coordinador decide la aprobación final o
solicita corrección. El PDF aprobado conserva tres firmas, en orden: docente,
coordinación y biblioteca.

Esta decisión reemplaza la incorporación del Decano y la cuarta etapa de I-173. Se
conserva el orden Biblioteca → Coordinación de I-173. La revisión enviada y la aprobación
siguen inmutables. Las revisiones nuevas vuelven a Biblioteca.

## Trazabilidad

RF-017..045, RF-062..069; RN-009..027; CU-04..07; UI COR-05..10, BIB-01..03,
DOC-07/08/10, ADM-02/06; CP-F revisión, aprobación, firmas y roles. DEC-01/02 se
retiran del inventario vigente. PV-07 sigue pendiente para fidelidad del DOCX oficial;
PV-16 sigue pendiente para edición excepcional del coordinador.

## Implementación

- La aprobación final y la exportación ocurren en la decisión de Coordinación.
- La etapa vigente se deriva solo de la verificación de Biblioteca y la aprobación.
- No se asigna ni activa el rol Decano, ni se ofrecen sus rutas, paneles o avisos.
- La firma de Biblioteca completa el documento.
- Una migración nueva restringe roles, etapas y firmas a los valores vigentes. Debe
  detenerse si existen asignaciones, firmas, devoluciones o aprobaciones históricas de
  decanato que requieran una decisión de conservación antes de restringir el esquema.
- Se sincronizan API, SPA, plantilla inicial, OpenAPI y trazabilidad.

## Verificación

OpenAPI regenerado. Vue `typecheck`, ESLint directo, Pint y PHPStan pasan. Las pruebas
de flujo y firmas existentes se actualizaron, pero la ejecución de pruebas sigue
pendiente. La migración no se ejecuta sobre producción como parte de esta edición.

La comprobación de solo lectura de la base indicada por las instrucciones locales
confirmó la conexión, pero mostró un esquema de otra aplicación, sin la tabla
`asignaciones_rol`. Por ello no se puede evaluar allí la precondición de la migración
ni presentarla como la base de producción de este repositorio.
