# I-85 — Restauración fiel de la interfaz monolítica

## Estado

Plan activo. Ningún bloque se considera terminado hasta contar con evidencia contra la
fuente canónica. Se descartan las marcas de finalización del plan anterior porque
mezclaban equivalencia funcional con paridad real de interfaz.

## Trazabilidad

- Requisitos: `RF-001..074`, `RNF-018..023`, `RNF-029..034`.
- Reglas: `RN-001..034`.
- Casos de uso: `CU-01..17`.
- Pantallas: `UI-01..04`, `ADM-01..12`, `COR-01..15`, `DOC-01..10`.
- Pendientes que permanecen abiertos: `PV-07`, `PV-13`, `PV-14`, `PV-16`, `PV-18`
  y `PV-19`.

## Fuente canónica e invariantes

La referencia visual y funcional inmutable es el commit `4e33b6d` de:

```text
/home/giossue/Documentos/proyecto-grado-silabos
```

La aplicación continúa distribuida:

- Vue 3, Vue Router y TypeScript en el frontend;
- Laravel como API JSON bajo `/api/v1`;
- Sanctum, cookie HttpOnly y CSRF;
- PostgreSQL y Redis como en la arquitectura vigente;
- sin Inertia, Wayfinder ni vistas de producto servidas por Laravel.

La única adaptación permitida es la frontera de datos y navegación: `Link` se convierte
en `RouterLink`, `Form`/`useForm` en clientes API tipados, `usePage().props` en estado
explícito y `Head` en metadatos de Vue Router. No se rediseña, simplifica, reagrupa ni
materializa una pantalla que no exista en la referencia.

## Bloque 1 — Analizar y corregir inconsistencias

- [ ] Construir una matriz completa entre las 38 páginas de referencia y las rutas de la
      SPA, incluyendo navegación, layouts, componentes, textos, estados y acciones.
- [ ] Clasificar cada artefacto como `identico`, `adaptado_api`, `faltante`, `alterado` o
      `extra`, con una justificación versionada para toda adaptación.
- [ ] Comparar automáticamente `<template>`, estilos, componentes utilizados y rutas
      contra un manifiesto fijado al commit `4e33b6d`.
- [ ] Portar los componentes originales faltantes y sustituir toda reconstrucción por la
      fuente canónica, modificando solo la frontera API.
- [ ] Retirar rutas, enlaces, pantallas, controles y pruebas añadidos sin referencia.
- [ ] Restaurar estructura, orden, textos, estados, responsive y composición originales
      en autenticación, superficies globales, Administración, Coordinación y Docencia.
- [ ] Auditar editor de plantilla, editor de sílabo e IA por separado debido a su tamaño
      y conservar sus componentes canónicos sin sustitutos activos.
- [ ] Confirmar cero dependencias productivas de Inertia/Wayfinder y cero elementos
      visibles sin correspondencia.

La ruta añadida `/coordinacion/configuracion-ia` no pertenece a la interfaz restaurada
porque no existe en el monolito. Su contrato API se conserva sin exposición en la
navegación hasta contar con un diseño aprobado independiente.

**Aceptación:** las 38 páginas tienen correspondencia explícita; no quedan artefactos
`faltante`, `alterado` o `extra`; cada diferencia `adaptado_api` está limitada al script
o adaptador de datos y documentada.

## Bloque 2 — Verificar paridad funcional distribuida

- [ ] Ejecutar recorridos completos de Administrador, Coordinador y Docente contra
      Laravel, PostgreSQL y Redis aislados.
- [ ] Verificar sesión, selección de ámbito, permisos, formularios, tablas, hojas,
      diálogos, autoguardado, observaciones, revisiones, documentos e IA.
- [ ] Cubrir carga, vacío, error, `422`, `409`, `401`, `419`, reintentos y ausencia de
      respuestas `5xx`.
- [ ] Comparar todas las acciones visibles con la referencia: ninguna faltante y ninguna
      adicional.
- [ ] Mantener OpenAPI y `src/api/*` sincronizados; cualquier ajuste backend alimenta la
      interfaz canónica y no la redefine.

No se prevén migraciones ni cambios del modelo de datos en este incremento.

## Bloque 3 — Verificar paridad visual y accesibilidad

- [ ] Preparar datos deterministas equivalentes para referencia y SPA.
- [ ] Capturar todas las rutas en claro a 1440 px, oscuro a 1440 px y claro a 360 px.
- [ ] Comparar estructura, dimensiones, tipografía, colores, espaciado, iconos, tablas,
      documentos y overlays; enmascarar solo horas, UUID y cursores dinámicos.
- [ ] Probar teclado, foco, cierre y devolución de foco en diálogos/sheets, navegación
      móvil y ausencia de overflow global.
- [ ] Corregir o registrar para aceptación explícita toda diferencia visual; el modo
      informativo del comparador no constituye aprobación.

## Bloque 4 — Limpieza, puertas finales y preview

- [ ] Eliminar código, pruebas, capturas y dependencias huérfanas de la interfaz
      sustituta.
- [ ] Ejecutar ESLint, TypeScript, Vitest, build, contrato SPA–OpenAPI, Playwright de los
      tres roles, `composer verify` y `git diff --check`.
- [ ] Construir y probar las imágenes frontend/backend, runtime config, CORS, CSRF,
      rutas profundas, archivos privados y cabeceras.
- [ ] Publicar primero en preview y completar revisión manual.
- [ ] Pasar a producción únicamente con autorización explícita posterior.

## Criterio de finalización

I-85 termina solamente cuando la SPA conserva la separación por API y reproduce el
monolito en rutas, componentes, comportamiento y presentación para todos los roles, sin
superficies inventadas, con todas las puertas automáticas verdes y aceptación visual del
preview.
