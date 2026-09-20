# I-92 — Embeddings de fuentes académicas

## Estado

En implementación. La fuente canónica continúa en `fuentes_academicas` y el índice
derivado de recuperación continúa en `fragmentos_fuente_academica`; no se crea una
tabla independiente de embeddings.

## Decisión técnica

- PostgreSQL usa `pgvector` dentro de la misma base transaccional.
- El modelo inicial es `text-embedding-3-small` con 1536 dimensiones; permite usar
  HNSW con pgvector y es suficiente porque los documentos se fragmentan antes de
  vectorizarse.
- Cada fragmento conserva texto, ruta de encabezados, posición y embedding.
- La búsqueda conserva el índice GIN en español y añade recuperación semántica por
  distancia coseno; si embeddings está desactivado o indisponible, se degrada a texto.
- PaddleOCR-VL 1.6 produce el documento JSON canónico. Después se reconstruyen los
  fragmentos estructurados y se generan embeddings en la cola `ia`.

## Alcance

- RF-037..054; CU-07 y CU-08; DOC-04..06.
- RN-015, RN-016, RN-020..024 y RN-028..030.
- RNF-013, RNF-016, RNF-017, RNF-035 y RNF-036.
- I-72, I-91; PV-12, PV-13, PV-14 y PV-18 siguen requiriendo validación de operación,
  proveedor y aceptación antes de declarar producción definitiva.

## Flujo

```text
PDF → PaddleOCR → documento JSON → fragmentos ordenados → embeddings → pgvector
```

El coordinador ve el documento estructurado completo. La búsqueda vectorial devuelve fragmentos por
similitud; la posición persistida permite reconstruir el orden original cuando se
necesite presentar el documento o la evidencia.
