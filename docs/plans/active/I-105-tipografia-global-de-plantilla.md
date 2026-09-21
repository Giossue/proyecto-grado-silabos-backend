# I-105 — Tipografía global de la plantilla

## Objetivo

Mantener una sola fuente de verdad para los tamaños tipográficos de la plantilla:
la configuración global de título, bloque y campo. Las celdas no deben introducir
un tamaño de fuente independiente.

## Alcance

- Retirar el selector de tamaño de fuente del editor de tablas.
- Mantener fondo, color y alineación con un valor visual predeterminado, sin exponer
  la palabra «heredado».
- Etiquetar la escala de contenido como «Campo», porque aplica al campo completo.
- Ignorar y retirar al normalizar el atributo histórico `fontSize` de las celdas,
  para que las plantillas existentes adopten la escala global.
- Evitar que la exportación DOCX vuelva a aplicar tamaños por celda.

## Criterios de aceptación

- Personalizar plantilla muestra `Título`, `Bloque` y `Campo`.
- El editor de tablas no ofrece tamaño de fuente por celda.
- Los valores predeterminados visibles son «Sin fondo», «Predeterminado» y
  «Predeterminada»; no aparece «heredado» en esos controles.
- La vista y DOCX usan la escala global de campo dentro de las tablas.

## Verificación

- Pruebas de normalización documental y exportación DOCX.
- Typecheck, lint, pruebas y build del frontend.

