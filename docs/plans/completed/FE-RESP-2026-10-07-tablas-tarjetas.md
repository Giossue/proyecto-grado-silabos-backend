# Responsividad: tablas a tarjetas y documentos

Pedido confirmado: convertir las tablas de listados en tarjetas móviles y corregir
los restantes hallazgos de la revisión responsiva.

## Alcance e IDs

RNF-018..023; UI-01..04, ADM-02/04/06/10/12, COR-05/13..16,
DOC-02/04/10, BIB-02/03; CP-N experiencia/accesibilidad y CP-F listados/editor.
RF-001..026/034..075; RN-001..034; CU-01..04/06..18 como contexto de los
módulos; sus reglas, autorización y persistencia no cambian.
PV-19 mantiene la aceptación en dispositivos y navegadores reales pendiente.

## Implementación

- [x] Un registro por tarjeta bajo 640 px, con todos sus datos etiquetados y acciones.
- [x] Conservar tablas amplias y tablas documentales con desplazamiento interno.
- [x] Paginación compacta móvil con página actual, total y controles accesibles.
- [x] Papel a tamaño constante dentro de una región desplazable, también al cargar.
- [x] Sincronizar esqueletos, especificación y trazabilidad.
- [x] Revisar estados cargados/vacíos, datos largos, teclado, temas y anchos.
- [x] Actualizar rutas de captura y cobertura de Biblioteca y tablet.

## Verificación y límites

Revisión local del 2026-10-07 por Codex, frontend basado inicialmente en `f6f7404`;
implementación frontend en el commit `c0caa86`. Evidencia:

- Chromium: tarjetas con cinco columnas, correo y carrera largos, estados vacíos,
  menú por teclado, instancia única de matriz editable, tablas documentales amplias,
  paginación de 10 000 páginas con límites y navegación por teclado, plantilla cargada
  vertical/horizontal de 16 semanas y esqueletos, desplazamiento por teclado, listado
  y detalle cargados de Biblioteca, sílabo editable con pestañas/asistente y límite de
  sidebar a 768 px. Claro/oscuro a 320, 360, 639, 640, 767, 768, 1024 y 1440 px
  según la superficie; 720 px comprueba reflujo de un escritorio de 1440 px reducido
  a la mitad, sin acreditar zoom real del navegador.
- `responsive-layout.spec.ts`, `curriculum-drag.spec.ts`,
  `frontend-components.spec.ts` y `loading-skeletons.spec.ts`: 26 casos distintos
  aprobados. La malla cargada conserva zoom, desplazamiento y arrastre; los esqueletos
  respetan movimiento reducido. La geometría comprueba recortes de ancestros,
  accesibilidad de controles y desbordamiento de celdas, además del ancho global.
- Capturas sintéticas de tarjetas, sílabo y revisión de Biblioteca en ambos temas en
  `test-results/`; revisión visual local realizada. Son artefactos regenerables.
- `npm run lint` aprobado, incluidas cuatro regresiones de reglas locales.
  `npm run build` incluye TypeScript y compila correctamente.
- Pruebas focalizadas de plantilla, constructor y sílabo: 52 aprobadas.
  Suite Vitest completa: 310 aprobadas, una omitida y dos fallos previos en
  `AppShell.test.ts:103` (espera que no exista Proyecto de carrera) y
  `router.test.ts:143` (ruta de decanato). Los mismos fallos se reprodujeron en una
  copia aislada de `f6f7404`, sin los cambios de esta unidad.
- `git diff --check` aprobado en ambos repositorios.

El marcador `data-table-actions` conserva la identificación del menú al componer
`as-child`: `data-slot` se reemplaza al combinar las primitivas. Esto permite presentar
el pie de acciones de la tarjeta y mantener la última columna fija en tablas amplias.

La API local tiene pendientes las migraciones `000202` a `000208`; el registro de
tipos encuentra clases antiguas (`texto_libre`/`referencia_maestra`) y devuelve 500 en
plantilla, estructura y detalle docente. Se verificó el historial de migraciones en
lectura. La base local carece además de cuenta de Biblioteca. Las páginas complejas
se verificaron con JSON sintético interceptado; la captura autenticada general no se
ejecutó. Su inventario ahora usa rutas vigentes, incluye Biblioteca y tablet, ambos
temas y rechaza respuestas 500 en vez de aceptar páginas de error como evidencia.
Actualizar ese entorno y confirmar la matriz real de dispositivos/navegadores y
zoom 200 % de PV-19 permanecen pendientes; no son criterios declarados aprobados.

Reversión: revertir únicamente los cambios de esta unidad, sin operaciones de datos.

## Ajuste visual posterior: bordes y fondos

El responsable indicó que las tarjetas no se distinguían. La última fila heredaba
`[&_tr:last-child]:border-0` de `TableBody` y el primer fondo `card` coincidía con
el contenedor. Se ajustaron únicamente los estilos móviles y su esqueleto: borde
`input` en todas las tarjetas, incluida la última/única, fondos sólidos alternos
`card`/`secondary` y sombra de superficie sobre un conjunto `background` con padding.
No cambia el límite de 640 px, los datos, acciones, tablas documentales o escritorio.
Verificación puntual: revisión de especificidad y `git diff --check`; ESLint del
esqueleto. No se añadieron ni ejecutaron pruebas ni un build en este ajuste.
