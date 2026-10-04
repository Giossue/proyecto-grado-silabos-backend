# FE-LOAD-2026-10-04: Cargas acordes con la interfaz vigente

## Estado

En curso.

## Trazabilidad

RF-001..026/034..075; RNF-018..023/029..034; RN-001..034 como contexto de las
pantallas, sin modificar sus reglas; CU-01..04/06..18; UI-02..04,
ADM-01..12, COR-01..10/13..15, DOC-01..10, BIB-01..03 y DEC-01..02;
CP-N accesibilidad/experiencia y CP-F identidad/panel/listados/editor/revisión;
PV-19 para aceptación con dispositivos reales.

## Resultado demostrable

Los esqueletos de carga del frontend reproducen la estructura actual de cada pantalla:
filtros, tablas, paginación, indicadores por rol, selección de ámbito, malla, editor,
revisión y documentos. Respetan las superficies y dimensiones en ambos temas y no
introducen controles enfocables ni desplazamiento horizontal global en móvil.

## Decisiones y supuestos

- Pedido explícito del responsable el 2026-10-04 de actualizar todas las cargas tras
  cambios de interfaz. La estructura renderizada actualmente es la referencia visual.
- Se conserva el contrato JSON y el comportamiento académico, de permisos y persistencia.
- No hay una decisión POR VALIDAR que impida esta corrección visual reversible.
  PV-19 conserva su aceptación institucional y de dispositivos reales pendiente.
- Las cargas iniciales de contenido usan Skeleton; acciones de guardado, envío,
  autenticación e IA conservan Spinner o Progress según la operación.

## Cambios previstos

- Frontend: descomponer las variantes visuales en composiciones reutilizables con las
  primitivas locales; ajustar los parámetros de las llamadas al contenido visible.
- Accesibilidad: un anuncio de carga por región, contenido decorativo oculto, respeto a
  movimiento reducido y adaptación de tamaños a 360, 768 y 1440 px.
- Documentación: descripción duradera de las cargas y actualización de trazabilidad.

## Pruebas

Pruebas de componentes para accesibilidad y las regresiones de estructura identificadas;
lint, TypeScript, suite Vitest y build frontend. Chromium para verificar geometría,
desbordamiento, temas y movimiento reducido con contenido sintético sin backend remoto.

## Pasos

- [x] Inventariar cargas y compararlas con las páginas actuales.
- [ ] Actualizar composiciones y llamadas de todas las pantallas afectadas.
- [ ] Verificar accesibilidad, responsive y regresiones con las puertas frontend.
- [ ] Sincronizar documentación y trazabilidad; cerrar con evidencia.

## Riesgos y reversión

Las cargas se muestran con datos todavía desconocidos, por lo que las cantidades de
filas/campos son aproximaciones visuales; los datos ya disponibles determinan el rol y
el modo de vista. Revertir los archivos de presentación restablece las cargas anteriores
sin operaciones de datos ni migraciones.

## Evidencia de cierre

Pendiente de verificación.
