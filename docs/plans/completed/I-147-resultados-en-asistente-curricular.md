# I-147 — Resultados efectivos en el asistente curricular

## Decisión del responsable, 2026-09-27

La acción «Resultados de aprendizaje» del menú de cada materia en Malla duplica el
asistente curricular. El responsable pide retirar ese editor como recorrido visible y
consultar los resultados efectivos, con procedencia, desde el único asistente en Malla.

## Alcance y trazabilidad

- I-144/I-145; RF-017..026, RF-034..045, RN-009..016, CU-04/CU-05,
  COR-13 y RNF-001..010/024. La prioridad de valores importados confirmados sobre
  la base manual histórica ya está confirmada.
- El índice del asistente proyecta los datos curriculares confirmados de la carrera y
  asignaturas. Para `resultados_aprendizaje`, presenta la base I-144 solo cuando no
  hay un valor genérico efectivo para esa materia. La procedencia antigua conserva
  nombre y ubicación sin inventar un extracto que nunca se guardó.
- No se borran filas de las bases I-144 ni revisiones fotografiadas. No cambia el
  esquema ni se necesita migración de producción.
- El acceso manual deja de aparecer en Malla y sus rutas GET/PUT se retiran. El
  contenido histórico solo se lee como respaldo; ninguna ruta vuelve a escribirlo.

## Trabajo

- [x] Añadir proyección de datos efectivos y procedencia al índice autorizado del asistente.
- [x] Mostrar «Datos confirmados» en el asistente y retirar la acción manual de Malla.
- [x] Retirar rutas y código de escritura manual, conservando la base histórica.
- [x] Sincronizar contrato, documentación y pruebas de prioridad/alcance.
- [x] Ejecutar verificaciones aplicables y publicar en ambos repositorios.

## Verificación

- Backend: `composer verify` pasó Pint, PHPStan sin errores y 394 pruebas con
  4847 aserciones. La importación compara el dato propuesto con el respaldo I-144,
  rechaza huellas obsoletas, limita la lectura a la carrera activa y conserva la base
  antigua después de confirmar la importación.
- Frontend: lint, tipos, 204 pruebas aprobadas y una omisión preexistente, contrato
  de 101 consumidores frente a 111 operaciones y build pasaron. El recorrido E2E
  documenta el asistente único y la ausencia del menú manual.
- OpenAPI se regeneró. Las rutas GET/PUT manuales ya no están en el contrato. No hay
  migración ni borrado de datos productivos en I-147.
