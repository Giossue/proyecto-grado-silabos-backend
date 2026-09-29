# I-151 — Plantilla por «quién llena» y datos curriculares declarados

## Decisión del responsable, 2026-09-28

El constructor de plantilla exige conceptos técnicos (tabla repetible, variables, Sí/No
condicional) que Administración difícilmente entiende. Se adopta la opción A: la
plantilla manda. Administración declara qué datos salen de los documentos de carrera;
Coordinación solo los llena mediante importación y el agente extrae únicamente esos
datos.

## Alcance y trazabilidad

- ADM-06, COR-16, I-142, I-145, I-149, I-150; RF-017..026, RF-034..045, RN-009..016,
  CU-04/CU-05, RNF de usabilidad.
- Al crear una parte, ADM-06 pregunta «¿Quién llena esta parte?»:
  - **El docente la escribe**: párrafo, lista con viñetas, lista numerada o tabla que se
    llena por filas (tipos existentes `text`, `bulleted_list`, `numbered_list`, `table`).
  - **El docente elige de una lista**: selección múltiple con listas curriculares.
  - **Sale sola de los documentos de la carrera**: parte fija con el dato automático. Se
    elige un dato ya declarado o se declara uno nuevo (nombre, texto o lista, por
    asignatura o de toda la carrera). El tipo `automatic` solo existe al crear; se
    guarda como parte fija (`fixed`) con una variable `curricular_*`.
  - **Texto fijo**: I-154 lo integra como cuarta respuesta, «Nadie, es un texto fijo».
- Sí/No deja de ofrecerse al crear partes. Las partes Sí/No existentes y su uso en
  «Mostrar solo si» siguen funcionando.
- Extracción: el agente recibe los datos declarados y no propone conceptos nuevos. La
  preparación descarta valores de conceptos no declarados y los informa como
  observación. Sin datos declarados no se puede solicitar una importación. La
  aplicación conserva su comprobación de estructura.
- COR-16 muestra qué datos buscará la importación y guía el recorrido en cuatro pasos
  (Documentos, Análisis, Revisión y Listo), con menos carga visual en la carga.
- Sin cambios de esquema: `conceptos_curriculares` ya existe.

## Trabajo

- [x] Backend: parte automática al crear parte y sección, con declaración del dato.
- [x] Backend: extracción limitada a datos declarados, rechazo sin datos y observaciones.
- [x] Backend: catálogo de datos declarados en ADM-06 y COR-16; Sí/No fuera del menú.
- [x] Frontend: selector «¿Quién llena esta parte?» en crear parte, crear sección y editar.
- [x] Frontend: COR-16 muestra los datos que se buscarán.
- [x] Pruebas backend y frontend; OpenAPI; documentación y trazabilidad.

## Verificación

- Backend: `composer verify` completo (Pint, PHPStan y 397 pruebas).
- Frontend: lint, typecheck, 218 pruebas de Vitest y build.
