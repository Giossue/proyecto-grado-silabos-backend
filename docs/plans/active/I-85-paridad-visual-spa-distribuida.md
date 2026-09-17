# I-85 — Restauración fiel de la interfaz monolítica

## Estado

Implementación y verificación automática completadas. El plan permanece activo hasta
publicar un preview y obtener la aceptación visual manual contra la fuente canónica.

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

- [x] Construir una matriz completa entre las 38 páginas de referencia y las rutas de la
      SPA, incluyendo navegación, layouts, componentes, textos, estados y acciones.
- [x] Clasificar cada artefacto como `identico`, `adaptado_api`, `faltante`, `alterado` o
      `extra`, con una justificación versionada para toda adaptación.
- [x] Comparar automáticamente `<template>`, estilos, componentes utilizados y rutas
      contra un manifiesto fijado al commit `4e33b6d`.
- [x] Portar los componentes originales faltantes y sustituir toda reconstrucción por la
      fuente canónica, modificando solo la frontera API.
- [x] Retirar rutas, enlaces, pantallas, controles y pruebas añadidos sin referencia.
- [x] Restaurar estructura, orden, textos, estados, responsive y composición originales
      en autenticación, superficies globales, Administración, Coordinación y Docencia.
- [x] Auditar editor de plantilla, editor de sílabo e IA por separado debido a su tamaño
      y conservar sus componentes canónicos sin sustitutos activos.
- [x] Confirmar cero dependencias productivas de Inertia/Wayfinder y cero elementos
      visibles sin correspondencia.

La ruta añadida `/coordinacion/configuracion-ia` no pertenece a la interfaz restaurada
porque no existe en el monolito. Su contrato API se conserva sin exposición en la
navegación hasta contar con un diseño aprobado independiente.

**Aceptación:** las 38 páginas tienen correspondencia explícita; no quedan artefactos
`faltante`, `alterado` o `extra`; cada diferencia `adaptado_api` está limitada al script
o adaptador de datos y documentada.

## Bloque 2 — Verificar paridad funcional distribuida

- [x] Ejecutar recorridos completos de Administrador, Coordinador y Docente contra
      Laravel, PostgreSQL y Redis aislados.
- [x] Verificar sesión, selección de ámbito, permisos, formularios, tablas, hojas,
      diálogos, autoguardado, observaciones, revisiones, documentos e IA.
- [x] Cubrir carga, vacío, error, `422`, `409`, `401`, `419`, reintentos y ausencia de
      respuestas `5xx`.
- [x] Comparar todas las acciones visibles con la referencia: ninguna faltante y ninguna
      adicional.
- [x] Mantener OpenAPI y `src/api/*` sincronizados; cualquier ajuste backend alimenta la
      interfaz canónica y no la redefine.

No se prevén migraciones ni cambios del modelo de datos en este incremento.

## Bloque 3 — Verificar paridad visual y accesibilidad

- [x] Preparar datos deterministas equivalentes para referencia y SPA.
- [x] Capturar todas las rutas en claro a 1440 px, oscuro a 1440 px y claro a 360 px.
- [x] Comparar estructura, dimensiones, tipografía, colores, espaciado, iconos, tablas,
      documentos y overlays; enmascarar solo horas, UUID y cursores dinámicos.
- [x] Probar teclado, foco, cierre y devolución de foco en diálogos/sheets, navegación
      móvil y ausencia de overflow global.
- [x] Corregir o registrar para aceptación explícita toda diferencia visual; el modo
      informativo del comparador no constituye aprobación.

La revisión humana de los 99 pares dejó 96 capturas idénticas píxel por píxel. Las tres
restantes corresponden a la misma pantalla de Auditoría en claro, oscuro y móvil: la
SPA registra el inicio de sesión realizado por la prueba API y la referencia monolítica
queda vacía. La composición, controles y estados visuales coinciden; se conserva esta
diferencia de datos porque eliminar el evento debilitaría la auditoría de autenticación.

## Bloque 4 — Limpieza, puertas finales y preview

- [x] Eliminar código, pruebas, capturas y dependencias huérfanas de la interfaz
      sustituta.
- [x] Ejecutar ESLint, TypeScript, Vitest, build, contrato SPA–OpenAPI, Playwright de los
      tres roles, `composer verify` y `git diff --check`.
- [x] Construir y probar las imágenes frontend/backend, runtime config, CORS, CSRF,
      rutas profundas, archivos privados y cabeceras.
- [ ] Publicar primero en preview y completar revisión manual.
- [ ] Pasar a producción únicamente con autorización explícita posterior.

### Preflight de publicación

El runbook vigente de Dokploy solo define las aplicaciones productivas de frontend y
backend, ambas vinculadas a la rama `main`. No hay aplicaciones, dominios ni ramas de
preview documentados. Por tanto, el siguiente paso no puede reutilizar los recursos
actuales sin convertir el preview en un despliegue productivo: requiere autorización
explícita para crear y configurar dos recursos aislados de preview o, en su defecto, una
decisión expresa de omitir ese ambiente.

## Criterio de finalización

I-85 termina solamente cuando la SPA conserva la separación por API y reproduce el
monolito en rutas, componentes, comportamiento y presentación para todos los roles, sin
superficies inventadas, con todas las puertas automáticas verdes y aceptación visual del
preview.

## Evidencia de implementación — 2026-09-16

- `npm run audit:visual-parity`: 38/38 páginas declaradas, 400/400 componentes
  canónicos presentes, 9/9 layouts presentes, 252/252 primitivas UI idénticas, cero
  rutas, componentes o dependencias prohibidas sin justificar.
- Captura determinista: 99 pares en claro/oscuro a 1440 px y claro a 360 px; 96 son
  idénticos píxel por píxel, ninguna superficie carece de contraparte y no hay dimensiones
  incompatibles. Los tres pares de Auditoría difieren únicamente por el evento de acceso
  que genera la API separada y quedan registrados para aceptación visual en preview.
- Playwright SPA→API: cinco recorridos aprobados para Administración, Coordinación,
  Docencia, foco en overlays y ausencia de overflow móvil; ninguna respuesta `5xx`.
- Frontend: ESLint, TypeScript, 20 pruebas Vitest, build Vite y contrato de 119
  consumidores frente a 121 operaciones OpenAPI aprobados.
- Backend: `composer verify` aprobado con Pint, PHPStan y 261 pruebas/3.511 aserciones.
- Imágenes OCI construidas con Podman. Smoke aprobado para runtime config del frontend,
  ruta profunda, CSP/cabeceras, salud del backend, CORS con credenciales y cookie CSRF.
- No se publicó preview ni producción: ambas acciones requieren autorización explícita.
