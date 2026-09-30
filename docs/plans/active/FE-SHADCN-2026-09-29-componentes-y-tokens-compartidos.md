# FE-SHADCN-2026-09-29: Componentes, tokens y datos compartidos

## Estado y autorización

En ejecución. El responsable pidió «ok arregla todo» después del informe
AUD-FE-2026-09-29. Se corrigen sus hallazgos en ambos repositorios cuando el contrato
de servidor sea necesario. No se publica ni se altera una base de producción.

## Trazabilidad

- RF-001..026/034..075, CU-01..04/06..18: formularios y superficies vigentes.
- RNF-018..023/029..034: consistencia, accesibilidad y mantenibilidad.
- UI-01..04; ADM-01..07/09..12; COR-01..10/13..16; DOC-01..10; BIB-01..03.
- RN-001..034: conservar alcance, permisos, fotografías e inmutabilidad.
- CP-F y CP-N de formularios, navegación, estructura académica y accesibilidad.
- I-10/I-13/I-101: tokens semánticos, primitivas y confirmación compartida.
- ADR-0006/0007: motores del lienzo y del documento, con controles shadcn.
- PV-07/PV-19: no se presenta una comprobación automatizada como aceptación real.
- PV-16 no se resuelve ni se amplían permisos. La tarea no depende de decisiones
  académicas por validar.

## Objetivo y aceptación

- Las acciones y controles de producto se componen con las primitivas locales.
- No quedan confirmaciones de ruta nativas, tooltips `title`, controles duplicados,
  colores literales locales ni muestras de datos institucionales fijas.
- Dimensiones comunes, estilo documental, etiquetas y validación se mantienen en
  fuentes compartidas; las opciones del dominio provienen del servidor.
- Se conservan la geometría dinámica documental y curricular, los mecanismos
  ocultos de formulario y el aviso de cierre de pestaña autorizados en I-101.
- Lint previene regresiones concretas y el verificador de contrato comprende la
  actualización multipart sin omitir la verificación del endpoint PATCH.
- El código, sus pruebas, el contrato y la documentación permanecen sincronizados.

## Pasos

- [ ] Revisar estado concurrente, reglas de interfaz y APIs de primitivas locales.
- [ ] Corregir controles, navegación sin guardar, tooltips y estados compartidos.
- [ ] Centralizar tokens, layouts, parámetros documentales y geometría compartida.
- [ ] Sustituir ejemplos fijos y compartir catálogos, etiquetas, fechas y límites.
- [ ] Revisar componentes inactivos y prevenir regresiones con reglas de lint.
- [ ] Reparar el verificador multipart y sincronizar OpenAPI si cambia el contrato.
- [ ] Ejecutar verificaciones frontend y servidor aplicables y revisión en navegador.
- [ ] Actualizar trazabilidad e informe con evidencia final.

## Cambios previstos

SPA: componentes locales y de dominio, páginas, utilidades, tema y reglas de ESLint.
Servidor: metadata de opciones existentes si no está disponible para sus consumidores,
sin cambios de esquema. Documentación: plan, arquitectura frontend y trazabilidad.

## Riesgos y reversión

Revisar teclado en grupos de elección, descarte con guardado fallido, selección de
certificado, interacción de desplegables, diálogos superpuestos y zoom del lienzo.
Preservar la paleta que se serializa en documentos, las medidas institucionales y los
cambios concurrentes de firma. La reversión consiste en retirar este cambio coherente
de archivos; no se ejecutan migraciones, rollbacks ni operaciones remotas.

## Verificación y evidencia

Pendiente de implementación. Se usarán las comprobaciones existentes con npm y
`composer verify` si se modifica el servidor, previa comprobación del entorno de pruebas.
