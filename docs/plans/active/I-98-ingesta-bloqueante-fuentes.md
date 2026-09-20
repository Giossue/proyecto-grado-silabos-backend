# I-98 — Ingesta bloqueante de fuentes académicas

## Estado

Implementado el 20 de septiembre de 2026 como ajuste de COR-11 e I-95.

## Objetivo

Convertir la creación de una fuente en un flujo único: metadatos y PDF, espera visible
hasta que OpenParser termine y navegación automática al documento editable. El detalle
de la fuente deja de ofrecer carga o reprocesamiento del PDF.

## Alcance y trazabilidad

- RF-027..033; RNF-006, RNF-013 y RNF-024; RN-013..016; CU-05; COR-11; CP-F.
- No cambia el esquema de datos, la fragmentación vectorial ni las condiciones PV-12 y
  PV-14 para activar OpenParser con documentos institucionales.

## Entregables

- [x] Progreso real de la ejecución disponible en el detalle API.
- [x] Diálogo modal bloqueante durante creación, envío y procesamiento.
- [x] Navegación automática a la fuente solo después de una ingesta completada.
- [x] Reintento y salida explícita ante error.
- [x] Eliminación de la carga/reprocesamiento PDF desde COR-11.
- [x] Pruebas, contrato y documentación sincronizados.
