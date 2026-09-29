# I-146 — Retirar Fuentes académicas y revisión IA vectorial

## Decisión del responsable, 2026-09-27

El responsable confirma que se retiran el módulo **Fuentes académicas** y el agente
que revisa el sílabo usando una base vectorizada. Esta instrucción reemplaza el alcance
anterior que mantenía esos flujos al agregar I-145. Se conserva el asistente documental
para importar datos curriculares y la ayuda para generar contenido de unidades según
carrera, materia y datos confirmados. La revisión, observación y aprobación humana se
mantienen; las validaciones determinísticas del sílabo continúan vigentes.

## Trazabilidad y alcance

- RF-008..045: configuración, datos académicos, convocatorias y elaboración.
- RF-046..054: retirar análisis/revisión RAG y sus decisiones; mantener ayuda de
  generación delimitada y las evidencias históricas existentes.
- COR-11/COR-13, ADM-06, DOC-04/DOC-06; RN-005..016, RN-020..024;
  RNF-001..010 y RNF-024..036. Detalle en la matriz actualizada.
- La eliminación funcional está autorizada por la instrucción actual. No depende de
  confirmar autoridad de documentos ni del límite académico de habilidades blandas.
- Ninguna operación destruye revisiones aprobadas, historial IA previo, documentos
  privados ni valores curriculares confirmados. No se requiere migración destructiva.

## Implementación

1. Retirar páginas, navegación, CRUD/API de Fuentes académicas y sus requisitos de
   preparación/apertura/reanudación de convocatoria. Coordinación carga documentos
   desde el asistente curricular, con extracción y confirmación por lote.
2. Desacoplar OCR/XLSX de indexación, embeddings y consultas vectoriales. La entidad
   documental interna y sus archivos se conservan para evidencia/importaciones;
   `source_ids` permanece como identificador interno compatible de documentos previos.
3. Retirar endpoints y controles de revisión/análisis IA, decisiones y aplicación de
   recomendaciones. Los jobs antiguos no pueden ejecutar revisiones o embeddings.
4. La ayuda de unidades usa datos estructurados de la fotografía de carrera/materia y
   el contenido de planificación del sílabo. No consulta el almacén vectorial ni
   identifica al docente en el prompt. No decide ni aplica cambios académicos sola.
5. Conservar lectura histórica y adaptar contratos, documentación, pruebas y accesos.

## Verificación

- Acceso a rutas retiradas rechazado; no existe enlace ni requisito de Fuentes.
- Apertura y reanudación sin documentos RAG; las demás reglas permanecen.
- Importación PDF/XLSX continúa con evidencia privada sin crear índice ni embeddings.
- Chat de generación recibe datos de su carrera/asignatura y no fuentes externas
  indexadas; aislamiento, privacidad, idempotencia e historial intactos.
- Pruebas antiguas de funcionalidades retiradas se sustituyen por pruebas del nuevo
  alcance, sin ocultarlas con skips. OCR/documentos conservan cobertura técnica.
- `composer verify` y puertas de frontend; OpenAPI regenerado desde su exportador.
- No afirmar conexión real con proveedores si sigue deshabilitada en el entorno local.

## Avance

- [x] Registrar la corrección y repartir dependencias.
- [x] Retiro de Fuentes y desacoplamiento documental.
- [x] Retiro del revisor y recuperación vectorial; ayuda de unidades.
- [x] Interfaz, contrato, documentación y pruebas coherentes.
- [x] Verificación final y publicación autorizada en la sesión.

La puerta canónica del backend pasó el 27 de septiembre de 2026: Pint, PHPStan sin
errores y Pest con 407 pruebas y 4963 aserciones. El contrato OpenAPI está regenerado.
Las verificaciones de frontend (lint, tipos, contrato, 209 pruebas pasadas y una
omitida, y build) pasaron. El recorrido de escritorio y móvil confirmó importación,
descarga privada y chat; este último usó un proveedor simulado y no acredita uno real.
I-146 no agrega ni altera tablas: la migración I-145 existente ya había sido aplicada
a producción y no se requiere otra para esta retirada.
