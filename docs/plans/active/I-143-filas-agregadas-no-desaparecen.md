# I-143: la fila agregada por el docente no desaparece

## Estado

Implementado localmente (backend y frontend). Sin migraciones. Reproducido y verificado
con Playwright contra la API local (sin interceptar escrituras).

## Trazabilidad

RF-037..044, RN-020..024, CU-07, DOC-01..05. No depende de una decisión `POR VALIDAR`.

## Causa raíz

`PATCH teacher/syllabi/{syllabus}/fields/{field}` devolvía las filas con los nombres de
columna (`datos`, `posicion`), mientras la carga del borrador usa `data`/`position`. El
editor reemplazaba sus filas con esa respuesta: las filas quedaban sin `data`, el render
fallaba (`Cannot read properties of undefined (reading '_kind')`) y la fila recién
agregada desaparecía tras «Guardando…". Además, lo escrito mientras viajaba el guardado
se pisaba con la respuesta.

## Reglas

1. La respuesta del guardado de filas usa la misma forma que la carga: `id`, `data`,
   `position`.
2. El editor solo adopta del servidor los `id` de las filas nuevas; contenido y orden
   siguen siendo los locales (`reconcileSavedRows`). Una fila vacía permanece hasta que
   el docente la quita; al enviar y en Word las filas vacías no cuentan (sin cambio).
3. Las partes tipo lista (una columna `texto`, sin cabeceras, unidades ni totales) se
   editan como lista: etiqueta de la parte con asterisco, un textarea por elemento con
   botón quitar y «Agregar elemento». Las tablas reales conservan su tabla con la
   etiqueta de la parte arriba. No cambia la firma electrónica.

## Pruebas

`ConvocationAndDraftTest::test_saved_rows_keep_the_draft_shape_and_empty_rows_survive`,
`TeacherSyllabusDetailPage.test.ts` (fila agregada y escritura durante el guardado),
`repeatableRows.test.ts`.
