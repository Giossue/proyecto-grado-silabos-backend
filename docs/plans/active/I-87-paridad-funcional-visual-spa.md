# I-87 — Paridad funcional y visual de la SPA con el monolito canónico

## Estado

Implementación en curso. Los bloques se ejecutan por rebanadas verificables; no se
modifica la base de datos ni se ejecutan despliegues. El auditor versionado, el registro
tipado de rutas (incluidos estados lógicos de páginas consolidadas), la recuperación de destino previsto y la base de skeletons ya están
verificados en la SPA. Se añadió un estado reutilizable de error con reintento a los
listados, paneles, comparación y estructura académica, además de una matriz inicial de
navegación por rol. En el backend se corrigieron los enlaces de recurso que entrega la
API de notificaciones para usar destinos canónicos de la SPA según el rol activo y se
añadieron regresiones para revisión, documentos exportados y sílabos docentes. La
revisión página por página, DTO, deep links y pruebas exhaustivas de estados continúan
abiertos.

Este incremento corrige navegación, redirecciones, estados de carga y adaptaciones de
las páginas de la SPA. No autoriza despliegue, cambios de base de datos, recuperación de
Inertia ni rediseños.

## Resultado demostrable

La aplicación continúa separada en frontend Vue y backend Laravel/API, pero una persona
que conocía el monolito encuentra las mismas rutas funcionales, composición, textos,
acciones, estados y jerarquía visual. La única diferencia visible permitida durante la
carga inicial es un skeleton que conserva la geometría aproximada de la pantalla final.

El objetivo «99 % igual» se interpreta como un criterio verificable, no como una cifra
decorativa:

- 100 % de las rutas GET visibles del monolito tienen destino canónico, alias documentado
  o justificación explícita de exclusión en la SPA;
- 38/38 superficies canónicas tienen mapeo fuente → página SPA y cero páginas de producto
  inventadas;
- 100 % de textos, orden, acciones, condiciones visibles y componentes de dominio
  coinciden, salvo estados propios de la frontera API;
- cero diferencias de plantilla o estilo sin clasificar;
- cero textos de carga sueltos como sustituto de la interfaz final;
- 100 % de la matriz de navegación, sesión, rol, carga, vacío, error y reintento pasa;
- la revisión manual por pantalla no encuentra una diferencia material de uso o diseño.

No se utilizará Playwright local ni preview en este incremento, por indicación expresa
del responsable. La evidencia será estática, unitaria, de componentes, contractual y
manual dirigida.

## Fuente canónica

La referencia inmutable sigue siendo el commit `4e33b6d` del monolito:

```text
/home/giossue/Documentos/proyecto-grado-silabos
```

Repositorios de implementación:

```text
Frontend: /home/giossue/Documentos/proyecto-grado-silabos-frontend
Backend:  /home/giossue/Documentos/proyecto-grado-silabos-backend
```

La auditoría exploratoria está en:

```text
temp/analyze-monolith-spa.mjs
temp/monolith-spa-analysis.md
```

Hallazgos de partida:

- 38 páginas canónicas y 38 mapeos existentes;
- 452/452 componentes del monolito presentes en la SPA por ruta relativa;
- 9/9 layouts presentes;
- 22 páginas de la SPA muestran estados o textos de carga inexistentes en el monolito;
- el guard conserva `redirect`, pero `LoginPage` no lo consume y siempre navega a `/`;
- varias páginas canónicas se consolidaron en una sola página SPA, lo que aumenta el
  riesgo de alterar composición y comportamiento;
- las rutas históricas y las rutas canónicas todavía conviven y necesitan una política
  única, no comparaciones dispersas.

La evidencia visual anterior de I-85 no cierra este problema: comparó estados ya
cargados y no demostró redirección posterior al acceso, historial, primera carga ni
equivalencia de cada superficie lógica cuando varias páginas se consolidaron. I-87
amplía la definición de paridad desde la captura final hacia el comportamiento completo.

## Trazabilidad

- Requisitos: `RF-001..007`, `RF-017..026`, `RF-038..074`,
  `RNF-005`, `RNF-013..023`, `RNF-029..034`.
- Reglas: `RN-001..004`, `RN-009..012`, `RN-017..034`.
- Casos de uso: `CU-01..17`.
- Pantallas: `UI-01..04`, `ADM-01..12`, `COR-01..15`, `DOC-01..10`.
- Decisiones: I-61, I-69, I-71, I-72, I-84, I-85 e I-86.
- Pendientes institucionales que no se cierran: `PV-07`, `PV-13`, `PV-14`, `PV-16`,
  `PV-18` y `PV-19`.
- No existe una decisión `POR VALIDAR` que bloquee corregir rutas, skeletons o paridad.

## Invariantes

- Laravel continúa siendo la autoridad de permisos, alcance y transiciones.
- La SPA continúa consumiendo `/api/v1` con Sanctum/CSRF; no se recuperan Inertia,
  Wayfinder ni rutas web de mutación.
- No se copian controladores, lógica de dominio ni secretos al navegador.
- Una ruta visible no concede acceso: toda API conserva Policies/Gates y alcance por
  registro.
- No se cambia el modelo de datos ni se crean migraciones.
- No se inventan cards, resúmenes, pestañas, formularios o textos ausentes en la fuente.
- Las diferencias inevitables por distribución se limitan a skeleton, error de red,
  reintento, expiración de sesión y conflicto CSRF.
- Una actualización silenciosa conserva los datos visibles; el skeleton se usa solo
  cuando aún no existe contenido que mostrar.

## Bloque 1 — Convertir la comparación en una puerta permanente

- [x] Mover la lógica útil de `temp/analyze-monolith-spa.mjs` a un auditor versionado en
  `scripts/`, manteniendo `temp` únicamente como evidencia exploratoria.
- [x] Fijar el commit `4e33b6d` en un manifiesto único con página fuente, página SPA,
  layout, ruta canónica, roles, acciones visibles y endpoints consumidos.
- [ ] Corregir el inventario para distinguir archivo físico, superficie lógica y estado
  de una misma página; una página consolidada no puede ocultar vistas faltantes.
- [ ] Clasificar cada diferencia como `transporte_api`, `navegacion_spa`,
  `estado_carga`, `estado_error` o `inconsistencia`.
- [ ] Hacer fallar el auditor ante rutas no declaradas, textos/acciones añadidos,
  componentes visibles sustituidos, páginas extras o diferencias sin justificación.
- [ ] Registrar por separado los alias temporales; nunca contarlos como otra pantalla.
- [ ] Generar un Markdown legible con el resultado por UI/ADM/COR/DOC.

**Aceptación:** el auditor explica todas las diferencias y no puede afirmar paridad solo
porque existe un archivo con nombre equivalente.

## Bloque 2 — Unificar todas las URLs bajo el contrato del monolito

- [ ] Extraer exclusivamente las rutas GET visibles de `routes/web.php` y
  `routes/settings.php`, expandiendo los grupos `admin`, `coordinacion` y `docente`.
- [x] Crear un registro tipado único en el frontend con:
  `path`, nombre lógico, rol permitido, componente, alias, breadcrumb y destino seguro.
- [x] Restaurar como canónicas las rutas visibles del monolito, incluidas autenticación,
  selección de ámbito, panel, notificaciones, fuentes, documentos y configuración.
- [x] Mantener las URLs ya publicadas por la SPA como alias temporales mediante
  `replace`, sin duplicar historial ni llamadas API.
- [x] Sustituir strings de destinos visibles dentro de páginas y componentes por helpers
  del registro tipado. Las cadenas que permanecen en `router.ts` describen el mapa de
  Vue Router y las cadenas bajo `src/api/` son endpoints HTTP, no URLs de navegador.
- [x] Preservar query de filtros, búsqueda, orden, paginación y modo cuando la ruta
  canónica lo admita.
- [x] Resolver rutas dinámicas sin perder parámetros de sílabo, revisión, convocatoria,
  fuente o usuario.
- [x] Mantener una ruta 404 explícita: una URL desconocida jamás deja `RouterView` vacío.
- [ ] Verificar que Nginx entregue `index.html` en todas las rutas profundas sin convertir
  errores API en respuestas del frontend.

### Política de compatibilidad

```text
Ruta canónica del monolito       → se conserva
URL anterior conocida de la SPA → replace hacia la canónica
URL de otro rol                  → panel del rol activo
URL desconocida                  → 404 explícito
Endpoint API de otro rol         → 403 del backend, sin redirección cosmética
```

**Aceptación:** navegación, breadcrumbs, recarga, marcador y Atrás/Adelante usan el
mismo contrato y no existen decisiones de ruta repartidas entre páginas.

## Bloque 3 — Reproducir la redirección “intended” sin exponerla en la URL

- [x] Eliminar `?redirect=...` como mecanismo principal visible.
- [x] Crear un almacén efímero de destino previsto en `sessionStorage`; nunca usar
  `localStorage`, cookies propias ni URLs externas.
- [x] Guardar únicamente rutas internas reconocidas por Vue Router y marcadas como
  autenticadas.
- [x] Rechazar valores absolutos, `//host`, esquemas, rutas desconocidas y destinos que
  no correspondan al rol finalmente activo.
- [x] Consumir el destino una sola vez después de login, segundo
  factor y cambio obligatorio de contraseña.
- [x] Si todavía no hay ámbito activo, conservar temporalmente el destino hasta que la
  persona elija rol/carrera; después validar su compatibilidad.
- [x] Si el destino pertenece a otro rol, descartarlo y abrir el panel del rol real.
- [x] Limpiar el destino al cerrar sesión, cambiar de cuenta o completar
  la navegación.
- [x] Mantener `419` como renovación CSRF/reintento seguro y `403` como denegación; no
  confundirlos con pérdida de sesión.
- [x] Usar `replace` en login, alias y recuperaciones para impedir ciclos del historial.

**Aceptación:** el flujo reproduce semánticamente `redirect()->intended(...)` del
monolito, pero dentro de la arquitectura SPA y sin open redirects.

## Bloque 4 — Sistema de skeletons coherente con la pantalla final

- [x] Reutilizar `components/ui/skeleton/Skeleton.vue`; no añadir otra librería.
- [x] Crear skeletons de dominio mínimos para las geometrías repetidas:
  encabezado, tabla con filtros, detalle, formulario, panel y documento.
- [x] Cada skeleton debe ocupar aproximadamente el espacio del contenido real y usar la
  misma grilla, ancho, separación y contenedor del componente canónico.
- [x] Sustituir los textos sueltos `Cargando…` en las 22 páginas detectadas.
- [x] Mantener un estado accesible `role="status"` con texto oculto para lector de
  pantalla; el skeleton decorativo usa `aria-hidden="true"`.
- [x] Respetar `prefers-reduced-motion` y evitar animaciones que provoquen mareo.
- [x] Usar skeleton únicamente en la primera carga sin datos.
- [x] En recarga, filtros, paginación, guardado o refresco conservar el contenido y
  mostrar pendiente en el control que originó la operación.
- [x] Evitar saltos de layout: títulos, acciones y breadcrumbs no cambian de posición al
  recibir la respuesta.
- [ ] Los errores sustituyen solo el cuerpo que falló y ofrecen reintento cuando la
  operación sea segura; vacío y error permanecen diferenciados.

### Aplicación por familia

Implementación parcial verificada: `LoadErrorState.vue` ya se utiliza en panel,
listados de administración y coordinación, fuentes, convocatorias, revisiones,
notificaciones, comparación, estructura académica, detalle de revisión, detalle de
fuente, detalle de usuario, plantilla, trabajos, documentos y selector de ámbito. Estas superficies separan
ahora el fallo de carga del error de mutación y ofrecen reintento sin inventar una acción
adicional. Las superficies con errores de mutación aún mezclados con la carga conservan
su alerta contextual hasta separar ambos estados.

El auditor ahora falla si una página que declara carga asíncrona no ofrece ese estado de
error reutilizable; la comprobación actual queda en 18/18 páginas asíncronas.

| Familia | Skeleton esperado |
| --- | --- |
| Paneles | encabezado y bloques con la misma distribución del rol |
| Listados | toolbar, cabecera de tabla, filas y paginación |
| Detalles | título estable, metadatos, secciones y acciones reservadas |
| Formularios | etiquetas y controles con alturas reales |
| Documento/sílabo | ancho de hoja, encabezados y bloques de contenido |
| Constructor de malla/plantilla | marco y herramientas; sin simular nodos inexistentes |

**Aceptación:** no queda texto visible de carga como contenido principal y la transición
al dato real no reordena la página de forma perceptible.

## Bloque 5 — Adaptar página por página desde la fuente, no reconstruirla

Para cada superficie, partir de su `<template>` y componentes canónicos. Solo se adapta
el script: props Inertia → DTO/API, `Link` → `RouterLink`, `Form/useForm` → cliente API y
`Head` → metadatos del router.

### 5.1 Globales

- [ ] UI-01: login, recuperación, restablecimiento, verificación y segundo factor.
- [ ] UI-02: selector de rol/carrera y cambio de ámbito.
- [ ] UI-03: notificaciones, lectura y estados vacíos.
- [ ] UI-04: perfil, seguridad, apariencia y restricciones de edición.

### 5.2 Administración

- [ ] ADM-01: panel y puesta en marcha.
- [ ] ADM-02/03: usuarios, detalle, roles, vigencia y acciones.
- [ ] ADM-04: facultades, campus, carreras y periodos en sus rutas originales.
- [ ] ADM-05/06/07: índice, lectura y edición de plantilla como estados/rutas separados,
  reutilizando el mismo documento canónico.
- [ ] ADM-09/10: trabajos y auditoría.
- [ ] ADM-11/12: configuración y convocatorias institucionales.

### 5.3 Coordinación

- [ ] COR-01: panel contextual de carrera.
- [ ] COR-02/03/04: listado, creación y seguimiento de convocatoria.
- [ ] COR-05..10: cola, revisión, comparación, corrección, aprobación y reapertura.
- [ ] COR-11/12: fuentes e informes.
- [ ] COR-13/14/15: malla, materias/paralelos y asignación docente.
- [ ] Separar adaptadores cuando una página SPA agrupe pantallas canónicas con
  composición distinta; compartir clientes y composables, no el template visible.

### 5.4 Docencia

- [ ] DOC-01/02: panel y listado de sílabos.
- [ ] DOC-03/04: resumen y editor continuo.
- [ ] DOC-05/06/07: validación, IA y confirmación de envío.
- [ ] DOC-08/09/10: observaciones, historial/comparación, aprobado y documentos.
- [ ] Preservar autoguardado, formulario continuo y asistente IA lateral definidos en
  I-71/I-72; no convertirlos en una hoja de impresión.

### Regla de revisión de cada página

- [ ] mismo layout y `PageFrame`;
- [ ] mismos títulos, descripciones y breadcrumbs;
- [ ] mismas acciones, iconos, orden y condiciones;
- [ ] mismos componentes de dominio y primitivas UI;
- [ ] mismos diálogos/sheets y comportamiento de foco;
- [ ] mismos estados vacío, bloqueo, solo lectura y error de negocio;
- [ ] única diferencia adicional: skeleton/error/reintento de red documentados.

**Aceptación:** cero páginas reconstruidas por aproximación y cero controles visibles sin
fuente canónica o decisión confirmada posterior.

## Bloque 6 — Alinear los DTO de la API con las props canónicas

- [ ] Comparar las props que entregaba cada controlador Inertia con el DTO consumido por
  la SPA.
- [ ] Si falta información, ampliar el Resource/endpoint API correspondiente; no calcular
  reglas académicas en Vue.
- [ ] Si sobra información sensible o fuera de alcance, filtrarla en Laravel.
- [ ] Conservar nombres de dominio en la interfaz y adaptar nombres físicos únicamente
  en `src/api/*`.
- [ ] Mantener paginación, filtros, errores `422`, conflictos `409`, expiración `401`,
  CSRF `419` y denegación `403` con contratos consistentes.
- [ ] Actualizar OpenAPI y el comprobador de consumidores cuando cambie un payload.
- [ ] No crear endpoints solamente para evitar reutilizar un componente existente.

**Aceptación:** los componentes reciben el mismo modelo de presentación que en el
monolito, aunque su transporte sea JSON.

### Rebanada backend aplicada

- [x] Enlazar notificaciones de sílabo de coordinación con la revisión más reciente en
  `/coordinacion/revisiones/{revision}`.
- [x] Enlazar notificaciones de sílabo docente con `/docente/mis-silabos/{syllabus}`;
  no se emite el alias histórico `/mis-silabos`.
- [x] Enlazar notificaciones de exportación con la superficie común de documentos en
  `/revisiones/{revision}/documentos` y comprobar la autorización del artefacto.
- [x] Mantener la ayuda de IA dentro del detalle docente canónico, sin inventar una
  ruta de navegador que la SPA no registra.
- [x] Verificar que el contrato OpenAPI no cambia: el ajuste solo corrige valores de
  `resource_path` y no agrega operaciones ni payloads.

## Bloque 7 — Pruebas sin Playwright

### Router y sesión — Vitest con memoria

Cobertura inicial verificada: rutas propias y ajenas de los tres roles, parámetros y
query, alias, 404, destino intended desconocido/externo y sesión sin ámbito. La tabla
completa de 401/2FA/cambio de contraseña y cambio de cuenta sigue pendiente.

- [ ] Tabla completa para sin sesión, sin ámbito, Administrador, Coordinador y Docente.
- [ ] Acceso directo, recarga simulada, alias, parámetros, query, 404 y rol incorrecto.
- [ ] Login → destino previsto; login → selector → destino; 2FA → destino.
- [ ] Logout/login de otra cuenta en la misma pestaña sin heredar ruta o rol.
- [x] Rechazo de open redirects y destinos no autorizados.
- [x] Historial con `replace` en login, alias y recuperación sin bucles conocidos.

### Componentes — Vue Test Utils

- [ ] Cada familia cambia de skeleton a contenido sin alterar estructura principal.
- [ ] Primera carga, refresco con datos, vacío, error, reintento y desmontaje durante una
  petición.
- [ ] Skeleton accesible, sin controles falsos enfocables y compatible con movimiento
  reducido.
- [ ] Las páginas consolidadas conservan todos sus estados canónicos.

### Contratos y calidad

- [x] Auditor de paridad: 38/38 superficies, 452/452 componentes, 9/9 layouts, cero
  diferencias sin clasificar.
- [ ] Verificador SPA–OpenAPI sin consumidores o operaciones huérfanas injustificadas.
- [x] `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build` y
  `npm run audit:visual-parity`.
- [x] Si cambia backend: pruebas Feature de notificaciones/API y `composer verify`.
- [x] `git diff --check` en ambos repositorios.

### Revisión manual dirigida

- [ ] Recorrer las 38 superficies con la referencia abierta al lado.
- [ ] Revisar escritorio 1440 px, móvil 360 px, claro, oscuro y zoom 200 %.
- [ ] Comprobar títulos, espacios, tablas, sheets, diálogos, menús, foco y skeletons.
- [ ] Registrar cada diferencia material en el informe; no aprobarla por intuición.

No se añade ejecución de Playwright local o preview a este plan.

## Bloque 8 — Secuencia de implementación

1. Auditor permanente y contrato tipado de rutas.
2. Destino previsto de autenticación y matriz de guards.
3. Skeletons compartidos y migración de las 22 páginas.
4. Globales y autenticación.
5. Administración.
6. Coordinación.
7. Docencia y editores especiales.
8. Ajustes mínimos de DTO/OpenAPI encontrados durante cada rebanada.
9. Puertas automáticas y revisión manual dirigida.
10. Documentación, trazabilidad y entrega sin despliegue.

Cada paso se implementará en rebanadas pequeñas. Una pantalla no se declara corregida
si solo se cambió su apariencia: navegación, permisos, carga, acciones y errores deben
quedar alineados juntos.

## Riesgos y mitigación

| Riesgo | Mitigación |
| --- | --- |
| Copiar Inertia accidentalmente | auditor de imports prohibidos y revisión del script de cada página |
| Open redirect después del login | almacén interno, validación por router/rol y consumo único |
| Skeleton distinto a la pantalla | componentes por geometría y revisión junto a referencia |
| Parpadeo en refrescos | conservar datos existentes y localizar el indicador pendiente |
| Regresión de permisos | backend sigue autorizando; pruebas `401/403` separadas del guard |
| Alias permanentes y confusos | inventario con fecha/justificación y uso canónico en todos los enlaces |
| Página consolidada pierde estados | matriz de superficies lógicas y adaptadores separados |
| “99 %” subjetivo | criterios cuantificables y lista manual por pantalla |
| Cambio masivo difícil de revertir | commits/rebanadas por familia, sin migraciones ni datos afectados |

## Reversión

- Los cambios de router, skeletons y páginas son reversibles por rebanada.
- No hay migraciones ni transformación de datos.
- Los alias se mantienen durante la transición para no romper marcadores.
- Un cambio API debe ser aditivo mientras frontend anterior y nuevo puedan coexistir.
- No se elimina una ruta/prop anterior hasta que su consumidor canónico esté verificado.

## Fuera de alcance

- Reintroducir Inertia o unir nuevamente los repositorios.
- Rediseñar el producto o añadir funciones ausentes en el monolito.
- Cambiar roles, permisos, esquema o datos de producción.
- Resolver decisiones institucionales abiertas.
- Certificar documentos oficiales o modelos de IA.
- Ejecutar Playwright local/preview.
- Desplegar o modificar producción sin una solicitud posterior explícita.

## Evidencia de cierre

El plan podrá pasar a verificación cuando existan:

- informe de rutas con 100 % de destinos clasificados;
- matriz 38/38 de superficies y estados;
- informe de cero diferencias no justificadas;
- inventario de los loaders detectados reemplazados por skeletons y estados de error;
- resultados verdes de lint, tipos, Vitest, build, paridad y contrato API;
- revisión manual dirigida firmada en `acceptance-status.md`;
- documentación y trazabilidad actualizadas.

I-87 termina únicamente cuando el frontend conserva la arquitectura distribuida y se
comporta y presenta como el monolito salvo las adaptaciones de red explícitamente
permitidas.
