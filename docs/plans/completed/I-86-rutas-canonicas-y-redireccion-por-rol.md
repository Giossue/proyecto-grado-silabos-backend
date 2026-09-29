# I-86 — Rutas canónicas y recuperación segura según el rol activo

## Estado

Implementación local del frontend completada; **preview, pruebas E2E y aceptación
manual pendientes**. No se modificaron sesiones, permisos, API ni despliegues.

## Problema observado

Una persona autenticada con un rol distinto de Administrador abre desde el historial:

```text
/admin/usuarios
```

La SPA muestra una página completamente vacía en lugar de llevarla a su área real.

No es un problema de caché. La causa está en el enrutamiento del frontend distribuido:

1. El monolito registra la pantalla como `/admin/usuarios`, pero la SPA la cambió a
   `/administracion/usuarios`.
2. `/admin/usuarios` no coincide con ninguna ruta de Vue Router.
3. La SPA no tiene una ruta final `/:pathMatch(.*)*`; una URL desconocida deja
   `<RouterView>` sin componente y produce la pantalla blanca.
4. El guard de sesión y rol solo se ejecuta con efecto útil sobre rutas que declaran
   `meta.requiresAuth`; una ruta no reconocida carece de esa metadata y el guard no
   corrige el destino.
5. La matriz de paridad declaró rutas distintas para referencia y SPA, y las pruebas
   visuales capturaron cada aplicación con su propio conjunto de URLs. Por eso pudieron
   aprobar la apariencia sin detectar la ruptura de navegación.

La API no concede acceso administrativo por este defecto. Sus endpoints siguen
respondiendo `403` cuando el rol activo no corresponde; el fallo es de experiencia y
recuperación de navegación en la SPA.

## Comportamiento de referencia

El monolito organiza cada pantalla bajo el área del rol activo:

```text
/admin/...
/coordinacion/...
/docente/...
```

Las direcciones cortas compartidas, como `/dashboard`, resuelven el rol activo y
redirigen a su panel específico. La selección de ámbito queda fuera de las tres áreas.
Un endpoint o recurso de otro rol continúa protegido por las políticas del servidor.

Para el caso adicional solicitado en la SPA, una URL conocida de otra área recuperada
desde el historial no mostrará un vacío ni intentará cargar información ajena: llevará
a la persona al panel de su rol activo.

## Trazabilidad

- Requisitos: `RF-001..007`, `RNF-018..023`, `RNF-029..034`.
- Reglas: `RN-001..004`.
- Casos de uso: `CU-01`, `CU-02`.
- Pantallas: `UI-01..04`, `ADM-01..12`, `COR-01..15`, `DOC-01..10`.
- Decisiones confirmadas: I-19 (un solo ámbito activo) e I-84 (SPA/API separadas).
- No depende de una decisión `POR VALIDAR`.

## Invariantes

- Laravel conserva toda la autorización efectiva; una redirección del frontend no
  sustituye Policies, Gates, alcance por carrera ni pertenencia del recurso.
- Solo existe un ámbito activo a la vez.
- Coordinación sigue eligiendo carrera explícitamente, aunque solo tenga una.
- Una URL no concede permisos ni revela si existe un recurso de otro alcance.
- No se reintroducen Inertia, Wayfinder ni páginas servidas por Laravel.
- No se atribuye a caché un estado que pueda demostrarse con la tabla de rutas.

## Bloque 1 — Fijar el contrato de navegación antes de corregir

- [x] Extraer del commit canónico `4e33b6d` todas las rutas visibles por rol y sus rutas
      cortas compartidas.
- [x] Construir una tabla versionada `ruta canónica → rol/área → componente SPA`,
      incluyendo parámetros dinámicos y configuración; la relación con endpoints se
      conserva en el contrato API existente y no se inventan endpoints nuevos.
- [x] Añadir pruebas que reproduzcan primero la pantalla blanca al abrir directamente
      `/admin/usuarios` y al recuperarla con Atrás/Adelante.
- [x] Cubrir en la matriz los estados: sin sesión, sesión sin ámbito, Administrador,
      Coordinador y Docente.
- [x] Separar rutas conocidas de otro rol, alias históricos y URLs realmente
      desconocidas; no tratarlas como el mismo caso.

**Aceptación:** existe una prueba automatizada por el defecto observado y ninguna decisión de
destino depende de comparar cadenas dispersas dentro de componentes.

## Bloque 2 — Restaurar las rutas canónicas del monolito

- [x] Cambiar las rutas principales de Administración de `/administracion/...` a
      `/admin/...`.
- [x] Restaurar el prefijo `/docente/...` para panel, sílabos, notificaciones y
      configuración.
- [x] Mantener y completar `/coordinacion/...` para panel, notificaciones,
      configuración y pantallas propias.
- [x] Restaurar las rutas de configuración dentro del área correspondiente, tal como en
      la referencia, sin duplicar páginas ni componentes.
- [x] Conservar temporalmente como alias las URLs ya publicadas por la SPA
      (`/administracion/...`, `/mis-silabos`, `/configuracion/...`, etc.), pero hacer que
      redirijan al destino canónico del ámbito activo.
- [x] Centralizar en un único mapa tipado las áreas, códigos de rol y paneles:

```text
administrador → /admin/panel
coordinador   → /coordinacion/panel
docente       → /docente/panel
```

**Aceptación:** la misma URL canónica se usa en documentación, navegación, pruebas y
capturas; no quedan dos inventarios de rutas divergentes.

## Bloque 3 — Corregir guard, historial y rutas desconocidas

- [x] Resolver la sesión antes de autorizar cualquier ruta autenticada o alias
      histórico.
- [x] Si no existe sesión, enviar a `/ingresar` conservando únicamente un destino
      interno validado.
- [x] Si falta ámbito activo, enviar al selector `/` sin intentar cargar la pantalla.
- [x] Si la ruta pertenece a otro rol, reemplazar el historial por el panel del rol
      activo; no ejecutar primero la llamada API de la pantalla ajena.
- [x] Si la persona autenticada abre `/ingresar`, llevarla al selector o a su panel,
      según tenga ámbito activo.
- [x] Añadir una ruta comodín explícita para que ninguna URL deje `<RouterView>` vacío.
      Las rutas conocidas o históricas se recuperan; las desconocidas muestran un 404
      real con una acción para volver al área vigente.
- [x] Usar `router.replace` en recuperaciones y alias para que Atrás no forme un ciclo
      entre la URL inválida y el destino correcto.
- [ ] Mantener `401` como cierre/renovación de sesión y `403` como denegación del
      servidor; no convertir indiscriminadamente cualquier error API en navegación.

**Aceptación:** ningún acceso directo, recarga, marcador ni navegación Atrás/Adelante
produce una pantalla blanca o permite observar una superficie de otro rol.

## Bloque 4 — Hacer que la auditoría de paridad detecte rutas rotas

- [x] Sustituir las rutas alternativas codificadas en
      `scripts/reference-parity-manifest.mjs` por las rutas canónicas reales.
- [x] Hacer que el auditor compare también el `path`, área, rol y política de cada ruta,
      no solo archivos, componentes y capturas.
- [x] Capturar referencia y SPA con la misma URL para cada superficie.
- [x] Fallar la verificación si una ruta canónica no coincide, si una ruta queda sin
      componente o si aparece una ruta visible no justificada.
- [x] Probar explícitamente los alias; no contarlos como pantallas adicionales.

**Aceptación:** volver a cambiar `/admin/usuarios` por `/administracion/usuarios` sin un
alias seguro hace fallar automáticamente la puerta de paridad.

## Bloque 5 — Pruebas de regresión por rol

- [ ] Administrador abre `/admin/usuarios` y permanece en esa pantalla.
- [ ] Coordinador abre `/admin/usuarios` desde historial y termina en
      `/coordinacion/panel`.
- [ ] Docente abre `/admin/usuarios` desde historial y termina en `/docente/panel`.
- [ ] Una persona sin ámbito activo termina en `/`, incluso si la URL solicitada era de
      Administración.
- [ ] Una persona sin sesión termina en `/ingresar`; después del acceso solo recupera el
      destino si corresponde a su rol activo.
- [ ] Un alias de la SPA anterior llega al equivalente canónico sin añadir una entrada
      duplicada al historial.
- [ ] Una URL desconocida muestra 404 y nunca una superficie vacía.
- [ ] Cambiar de ámbito actualiza inmediatamente el mapa de navegación y el siguiente
      destino.
- [ ] Cerrar sesión e iniciar como otra persona en la misma pestaña no reutiliza el rol
      ni el destino anterior.
- [ ] Las llamadas directas a endpoints de otro rol continúan respondiendo `403`.

Las pruebas se repartirán entre Vitest para el resolutor/guard, Playwright para acceso
directo e historial, y Feature tests Laravel para conservar la autorización del API.

## Bloque 6 — Verificación y entrega

- [x] Ejecutar lint, TypeScript, Vitest, build y auditor de paridad.
- [ ] Ejecutar Playwright de los tres roles; requiere la sesión/API del entorno de
      ejecución.
- [ ] Ejecutar las pruebas backend de sesión, rol activo y autorización cruzada.
- [ ] Verificar rutas profundas desde Nginx en la imagen final de la SPA.
- [ ] Probar manualmente recarga, marcador, Atrás/Adelante, cambio de ámbito y cambio de
      cuenta en una pestaña real.
- [ ] Publicar primero en preview y observar que frontend y API compatibles se despliegan
      juntos.
- [ ] Pasar a producción solo con autorización explícita y conservar rollback de ambos
      artefactos.

## Fuera de alcance

- Cambiar la estructura de roles o asignaciones en la base de datos.
- Relajar permisos del API para compensar una ruta de frontend.
- Rediseñar menús, páginas o componentes visuales.
- Redirigir silenciosamente un `403` de recursos fuera de alcance como si fuera un
  problema de navegación.
- Desplegar o alterar producción durante la fase de diagnóstico y planificación.

## Criterio de finalización

I-86 termina cuando las rutas visibles vuelven a coincidir con la referencia, toda URL
histórica conocida se recupera según el ámbito activo, ninguna ruta desconocida deja la
SPA vacía, el backend conserva sus denegaciones y las pruebas demuestran el comportamiento
para Administrador, Coordinador y Docente en acceso directo, recarga e historial.
