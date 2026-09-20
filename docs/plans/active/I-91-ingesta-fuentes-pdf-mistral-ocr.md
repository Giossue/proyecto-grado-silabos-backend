# I-91 — Ingesta de fuentes PDF con Mistral OCR

## Estado

Reemplazado por I-95 el 19 de septiembre de 2026. Mistral OCR y Markdown dejaron de
formar parte de la ingesta canónica.

Backend implementado y verificado localmente el 19 de septiembre de 2026. La activación
del proveedor en un entorno real continúa bloqueada por PV-12/PV-14.

## Trazabilidad

- RF-027..033, RN-013..016, CU-05, COR-11, CP-F fuente/evidencia.
- RNF-006, RNF-013 y RNF-024: privacidad, asincronía y trazabilidad.
- PV-12: tratamiento externo y privacidad de documentos.
- PV-14: proveedor/modelo de OCR y evaluación técnica.
- DT-13: ingesta PDF con un adaptador único.

## Resultado demostrable

Una Coordinación carga un PDF privado y obtiene, después de un trabajo asíncrono, un
Markdown estructurado por página mediante Mistral OCR 4.1. El resultado puede revisarse,
se indexa en fragmentos_fuente_academica y no se activa si la extracción falló.

## Decisiones y supuestos

- El PDF original es privado e inmutable.
- El modelo configurado es mistral-ocr-4-1.
- table_format='markdown', bloques y confianza se solicitan cuando el contrato final
  confirme sus límites de payload.
- No se usa pdfplumber, DeepSeek ni otro LLM de postprocesamiento.
- Las páginas vacías no activan un extractor alternativo.
- El Markdown sigue siendo el contenido consumido por el editor, el índice y la evidencia
  hasta que el producto apruebe otra representación.

## Implementado

- Dominio: estado de extracción y política de conservación del PDF original.
- Backend: puerto/adaptador Mistral OCR, caso de uso y trabajo idempotente.
- Datos: objeto PDF, ejecución, resultado por página y metadatos de modelo/opciones.
- Seguridad/auditoría: alcance por carrera, proveedor autorizado, huellas y auditoría.
- Trabajos/integraciones: cola asíncrona y reintentos acotados; los rangos de página
  quedan fuera de esta primera versión.

## Fuera de alcance

- Interpretación semántica garantizada de diagramas, gráficos o fotografías.
- Fallback silencioso a otra librería, modelo o LLM.
- Cambiar el proveedor general de asistencia IA de I-73.
- Ejecutar migraciones o habilitar el envío externo antes de resolver PV-12.

## Pruebas

- PDF textual con encabezados, listas, tablas, enlaces e imágenes.
- PDF escaneado y páginas sin texto: resultado explícito de revisión, sin fallback.
- Reintento de un rango de páginas sin duplicar contenido.
- Idempotencia por huella, modelo, versión del adaptador y opciones.
- Autorización por carrera y activación solo tras extracción exitosa.
- Reconstrucción del índice y preservación de la evidencia histórica.

## Pasos

- [x] Revisar documentación oficial de OCR 4.1, endpoint, respuesta y batch.
- [x] Documentar arquitectura, límites y decisión de no usar DeepSeek en la ingesta.
- [ ] Cerrar PV-12 y evaluar envío de documentos institucionales.
- [ ] Cerrar PV-14 con prueba de calidad, latencia y costo.
- [x] Diseñar migración y contrato de ejecución OCR.
- [x] Implementar adaptador, trabajo, persistencia, API y permisos.
- [x] Implementar pruebas y actualizar OpenAPI.
- [ ] Actualizar la matriz de trazabilidad con la evidencia de esta implementación.

## Riesgos y reversión

La salida Markdown puede perder significado visual, especialmente en diagramas y tablas
complejas. El PDF original permite revisar y reprocesar; la integración queda detrás de
un adaptador y se puede desactivar sin afectar el contenido transaccional existente.

## Referencias

- ../../architecture/academic-source-ingestion.md
- https://docs.mistral.ai/models/ocr-4-1
- https://docs.mistral.ai/api/endpoint/ocr
- https://docs.mistral.ai/studio/document-processing/basic_ocr
- https://docs.mistral.ai/resources/cookbooks/mistral-ocr-batch_ocr
