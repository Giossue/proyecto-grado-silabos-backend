# I-94 — Recursos visuales de fuentes académicas

## Estado

Reemplazado por I-95 el 19 de septiembre de 2026. Sus garantías de recursos privados se
mantienen, ahora sobre el documento JSON generado por OpenParser.

## Objetivo

Convertir las referencias de tablas e imágenes producidas por Mistral OCR en recursos
visibles y privados dentro del editor Markdown de fuentes académicas.

## Alcance

- Reemplazar las referencias de tablas por su contenido Markdown.
- Persistir las imágenes Base64 de Mistral como objetos privados inmutables.
- Servirlas mediante una ruta autenticada y limitada a la carrera de la fuente.
- Mantener el PDF y el Markdown como fuente de verdad; los objetos visuales son recursos
  derivados de una extracción.
- Permitir reprocesar una extracción anterior cuando cambie el adaptador OCR.

## Fuera de alcance

- Interpretar semánticamente diagramas, fotografías o fórmulas.
- Guardar Base64 en PostgreSQL o incrustarlo en los fragmentos vectoriales.
- Hacer públicas las imágenes o exponer una descarga sin autorización.

## Implementación

- [x] Inlinear tablas en el Markdown canónico.
- [x] Habilitar y materializar imágenes OCR en `objetos_almacenados`.
- [x] Agregar endpoint privado para imágenes de fuentes.
- [x] Actualizar la vista del editor para contraste de hoja y recursos.
- [x] Cubrir contrato OCR, persistencia, autorización y renderizado.
- [x] Actualizar arquitectura, OpenAPI y trazabilidad.
