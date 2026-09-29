# I-150 — Modales legibles y secciones automáticas de prueba

## Decisión del responsable, 2026-09-28

Los modales largos del constructor de plantilla se salían de la pantalla y el de
«Editar parte» mezclaba opciones que no aplican. Además, se pidió probar en la base
remota de pruebas qué secciones del sílabo deben llenarse solas desde los documentos
de carrera.

## Alcance y trazabilidad

- ADM-06, I-142, I-145/I-149; RF-017..026, RNF de usabilidad y accesibilidad.
- Todo `DialogContent` tiene altura máxima y desplazamiento interno: el título y las
  acciones de un modal largo siempre se pueden alcanzar.
- «Editar parte» muestra primero nombre y tipo; las listas curriculares y la hoja
  horizontal no aparecen en la ficha de identificación; «Cuándo se muestra» agrupa las
  condiciones con una sola explicación y solo ofrece preguntas de la misma sección
  (ver I-142). Sin preguntas disponibles, el bloque no aparece.
- Sin cambios de API ni de esquema.

## Datos de prueba aplicados en la base remota (2026-09-28)

Respaldo previo: `~/silabos-respaldos/2026-09-28-antes-de-borrar-periodo.dump`.

- Se borró el único período académico con su convocatoria, 43 programaciones,
  paralelos, asignaciones docentes y sílabos (sin revisiones enviadas).
- Se declararon los conceptos curriculares de lista `habilidades_blandas`,
  `habilidades_transversales`, `herramientas_tic`, `herramientas_tac`,
  `herramientas_ia`, `bibliografia_basica` y `bibliografia_complementaria`.
- Con la API de Administración: «Resultados de aprendizaje», «Herramientas
  pedagógicas, TAC e IA» y la bibliografía básica y complementaria pasan a texto fijo
  con datos automáticos; «Habilidades blandas» pasa a selección múltiple con opciones
  de las listas de la asignatura y transversales.
- Pendiente: aplicar la importación de prueba en COR-16 e importar el proyecto de
  carrera (resultados de aprendizaje). Sin esos datos, los datos automáticos obligatorios
  quedan vacíos y un sílabo nuevo no podría enviarse.

## Ajustes posteriores en la base remota (2026-09-28, I-151/I-154)

- «Resultados de aprendizaje» pasa a una parte «Sale sola de los documentos» con el dato
  declarado `resultados_de_aprendizaje` (lista, por asignatura), para que la importación
  lo busque. Reemplaza la variable anterior `resultados_aprendizaje_asignatura`.
- Se agrega «Contenidos mínimos» (`contenidos_minimos`, lista, por asignatura) al inicio
  de la sección de unidades.
- «Conducta y comportamiento ético» pasa a texto fijo con el texto institucional del
  sílabo real de Calidad de Software, sin los apartados propios de clases virtuales.

## Trabajo

- [x] Altura máxima y desplazamiento en `DialogContent`.
- [x] Reorganizar «Editar parte» y limitar condiciones a la sección.
- [x] Pruebas de condiciones por sección y de la ficha de identificación.
- [x] Typecheck, lint y suite completa de Vitest.
- [x] Cambios de datos en la base remota de pruebas, con respaldo.
