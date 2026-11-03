# Actualizar la tesis (docx) al sistema actual

Objetivo: corregir en `temp/tesis/proyecto Pintado-Arias.docx` lo que ya no coincide con el sistema.
Rama: main (temp/ está ignorado; la entrega es un .docx nuevo, no un commit). Verificación: lectura estructural del XML y comparación de textos.

## Specs

- S1: «ahora pasemos con la actualización de la tesis como tal, o sea el docx».
- S2: Alcance «Todo lo del sistema»: revisar el Cap. IV (requerimientos, arquitectura, diagramas, pantallas, programación y pruebas), los manuales de los anexos D y E y las conclusiones contra lo que el sistema hace hoy, y corregir lo desactualizado. Los Cap. I–III no se tocan, salvo que algo contradiga al sistema.
- S3: Entrega «Copia nueva»: un .docx nuevo junto al original, con la lista de cambios. El original queda intacto.
- S4 (supuesto): las imágenes (diagramas y capturas) que estén desactualizadas se listan para rehacerlas; en esta pasada no se regeneran.
- S5: «haz ambas cosas»: rehacer los diagramas y las capturas desactualizados de la lista de cambios.
- S6: «tienes onlyoffice en mi pc por si quieres comprobar»: revisar el resultado con OnlyOffice (convertidor x2t a PDF y luego imágenes de las páginas).
- S7: «quita la enimeraciones de paginas, hay 2; una arriba y abajo, quitalas.»

## Tasks

| ID | Specs | Ruta | Estado |
|---|---|---|---|
| T1 | S2 | delegada: explorador del Cap. IV 4.1–4.2 | hecha (20 diferencias, 10 figuras) |
| T2 | S2 | delegada: explorador del Cap. IV 4.3–4.4, más resumen, introducción y conclusiones | hecha (9 diferencias, más la propuesta de conclusiones) |
| T3 | S2 | delegada: explorador de los anexos D y E | hecha (30 diferencias, 16 capturas) |
| T4 | S2, S3 | inline: aplicar los cambios en una copia y validarla | hecha: `proyecto Pintado-Arias (actualizado 2026-11-03).docx`, +32 párrafos; la validación solo marca duplicados que ya traía el original |
| T6 | S7 | inline: quitar la numeración del encabezado y del pie | hecha: quitado el campo PAGE de footer1–3; no hay encabezados |
| T7 | S5 | delegada (Writer, paralelo): 13 figuras regeneradas; reemplazo en el docx inline | hecha |
| T8 | S5 | delegada (Writer, paralelo): 6 figuras del Cap. IV y 11 capturas del manual, solo lectura | hecha |
| T9 | S6 | inline: convertir a PDF con OnlyOffice y revisar las páginas | hecha: PDF de 278 páginas revisado |
| T5 | S3, S4 | inline: lista de cambios e imágenes a rehacer | hecha: `temp/tesis/cambios-2026-11-03.md` |

## Log

- L1 (2026-11-03): «sí, ahora pasemos con la actualización de la tesis como tal, o sea el docx».
- L2: respuestas: «Todo lo del sistema (Recomendado)»; «Copia nueva (Recomendado)».
- L3: el texto extraído está en /tmp/tesis-txt (borrador fuera del repositorio). Hallazgos iniciales: las secciones CONCLUSIONES y RECOMENDACIONES están vacías, y no hay pandoc ni LibreOffice en la máquina.
- L4 (2026-11-03): entregados el .docx nuevo y la lista de cambios. Pendientes: volver a correr las E2E, actualizar los índices en Word, rehacer las imágenes y completar las rutas y variables del Anexo E. No se pudo renderizar porque no hay LibreOffice.
- L5 (2026-11-03): «haz ambas cosas, tienes onlyoffice en mi pc por si quieres comprobar y quita la enimeraciones de paginas, hay 2; una arriba y abajo, quitalas.» La conversión a PDF funciona con x2t de OnlyOffice (flatpak), usando params.xml y la caché AllFonts.js.
- L6 (2026-11-03): entregados el docx y el PDF de la segunda pasada. La Figura 6 del original repetía la imagen de la Figura 5; se corrigió. Pendientes: capturas que dependen de un sílabo en revisión.
- L7 (2026-11-03): «pues termina lo pendiente, las pruebas no, porue eso falta hacerlo con los interesados». Hecho: capturas en una copia aislada de la base (ya borrada), índices recalculados (script herramientas-docx/indices-sin-pie.py), CU-16 «panel» corregido, secciones 4.9, 5.9, 6.15 y 6.16 en el Anexo D, y 5.7.6 quitada. Hallazgo: el docente no tiene enlace visible a Documentos de apoyo.
- L8 (2026-11-03): app: botón y modal «Documentos de apoyo» para el docente (frontend 3dfb5b7, backend fb58d643); manual 6.15 actualizado; PDF regenerado con Gotenberg.
