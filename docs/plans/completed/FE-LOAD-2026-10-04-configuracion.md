# FE-LOAD-2026-10-04-CONFIG: Carga completa de Configuración

## Estado

Completado técnicamente el 2026-10-04. Complementa FE-LOAD-2026-10-04 tras la pantalla
omitida señalada por el usuario. La aceptación con dispositivos reales sigue en PV-19.

## Trazabilidad

RF-001..007; RNF-018..023; RN-001..004; CU-01/02; UI-04;
CP-F identidad/segundo factor y CP-N experiencia/accesibilidad; PV-19.

## Resultado y decisiones

Configuración conserva su encabezado y navegación. Seguridad presenta el esqueleto del
formulario de contraseña y del segundo factor mientras consulta este último; después
de responder, incluso con error, revela el contenido y el aviso correspondiente.
Perfil representa sus campos mientras la sesión no tiene todavía sus datos.
Se conserva la consulta única existente y la autorización de identidad. No se agregan
esperas artificiales, requisitos académicos ni decisiones pendientes.

## Pasos y verificación

- [x] Componer los campos y ayudas de Perfil y Seguridad con Skeleton local.
- [x] Integrar la carga de la pantalla con el estado real de sesión/segundo factor.
- [x] Probar carga, respuesta y error, consulta única, navegación estable, responsive,
  temas y movimiento reducido; ejecutar lint, tipos y pruebas afectadas.
- [x] Actualizar fuentes de interfaz y trazabilidad con la evidencia.

## Riesgos y reversión

La finalización de la consulta de segundo factor debe liberar el formulario de
contraseña incluso si falla. Revertir los componentes de presentación y su evento
restablece la composición previa; no se modifican datos ni contratos de la API.

## Evidencia

- `npx vitest run src/pages/SettingsLoading.test.ts src/components/ManageTwoFactor.test.ts
  src/components/domain/LoadingSkeleton.test.ts`: tres suites, 31 pruebas aprobadas.
  Comprueban resolución y error del segundo factor, consulta única y perfil reactivo con
  permisos de consulta/edición, además de las variantes compartidas de carga.
- `npm run typecheck` y ESLint de todos los archivos afectados: aprobados.
- `VITE_API_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/settings-loading.spec.ts`:
  ocho casos aprobados. La pantalla real conserva navegación y título, no revela campos
  de contraseña durante la carga, resuelve la consulta o su error y conserva una sola
  petición. Responsive a 360, 768 y 1440 px en claro/oscuro, sin scroll global, y
  movimiento reducido comprobados.
- Se inspeccionaron las 12 capturas sintéticas en `/tmp/silabos-settings-loading`.
  Se agregaron líneas responsive para reproducir las ayudas que envuelven en móvil;
  tras ese ajuste pasaron otra vez los dos casos de 360 px y movimiento reducido, más
  ESLint puntual. No se usaron datos reales ni una API remota.
- Documentación de pantallas, arquitectura frontend y trazabilidad sincronizada.
