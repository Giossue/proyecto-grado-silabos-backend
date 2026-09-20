# I-89 — Hoja A4 y paginación dinámica de fuentes

## Estado

Su paginación A4 se conserva dentro de I-95; la persistencia Markdown fue reemplazada
por el documento JSON estructurado el 19 de septiembre de 2026.

## Trazabilidad

- RF-027..033, RN-013..016, CU-05, COR-11, CP-F fuente/evidencia.
- Reutiliza el motor de paginación visual de I-69 sin cambiar persistencia, permisos,
  Markdown almacenado ni exportaciones.

## Alcance

- COR-11 muestra el documento de la fuente sobre hojas A4 verticales con márgenes de
  2,5 cm.
- El contenido se reparte entre hojas según la geometría real del DOM y se recalcula al
  escribir, cambiar el tamaño disponible o cambiar el nombre de la fuente.
- Los saltos y separadores son transitorios de presentación; no entran en el Markdown.
- Esta extensión es exclusiva de fuentes académicas; las superficies que conservan hoja
  Carta no cambian.

## Verificación

- Comprobar contenido vacío, crecimiento a varias hojas, reducción a una hoja, tablas y
  listas.
- Revisar 360 px, escritorio, ambos temas y edición posterior al salto.
- `npm test`: 21 archivos y 81 pruebas correctas.
- `npm run typecheck`, lint de los archivos afectados y `npm run build`: correctos.
- La revisión visual en Chromium de varias hojas y 360 px queda pendiente de la matriz
  PV-19.
