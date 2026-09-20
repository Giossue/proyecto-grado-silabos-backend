# I-102: Editor docente con superficie documental completa

## Estado

Implementado y verificado automáticamente el 20 de septiembre de 2026.

## Trazabilidad

- RF-037..044; RN-020..024; CU-07; DOC-04.
- RNF-018..023, RNF-029..034; PV-19 para revisión manual responsive y de accesibilidad.
- Complementa I-06 e I-13 sin cambiar permisos, estados, autoguardado ni contrato de IA.

## Decisión de interfaz

La ruta de detalle de un sílabo docente usa un shell documental propio. No muestra el
sidebar ni el encabezado autenticado común, porque el formulario y el único Asistente IA
necesitan el ancho completo. Conserva únicamente el control reutilizable de tema en una
posición flotante y los servicios globales de toast/confirmación.

En escritorio el formulario y el asistente ocupan columnas reales: el formulario usa el
espacio flexible de la izquierda y el asistente conserva una columna de 24–28 rem,
pegada al viewport mientras se desplaza el contenido. En pantallas estrechas vuelven al
flujo vertical normal.

## Implementación

- [x] Registrar un layout dedicado para las rutas canónica y heredada de detalle docente.
- [x] Mantener `AppearanceToggle` sin reinstalar un encabezado global.
- [x] Mantener `Toaster` y `PurgeConfirmationDialog` fuera del shell general.
- [x] Ampliar la columna del asistente y ajustar su anclaje al viewport.
- [ ] Revisar manualmente teclado, claro/oscuro, 360 px y zoom 200 % conforme a PV-19.

## Verificación

La evidencia automatizada queda en la entrega del frontend: `npm run lint`,
`npm run typecheck`, `npm test` y `npm run build`.
