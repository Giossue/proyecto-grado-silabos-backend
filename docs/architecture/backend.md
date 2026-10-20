# Backend Laravel

El backend es solo API. Todas las rutas de producto están en `routes/api.php` bajo
`/api/v1`. `routes/web.php` solo tiene la raíz, la salud y los logos públicos.

## Recorrido de una escritura

```text
Ruta /api/v1/…
  → middleware: sesión Sanctum, cuenta activa, correo verificado, rol activo, límite de peticiones
  → Form Request: forma y tipos de los datos
  → Policy o Gate: rol activo + alcance (carrera, paralelo) + estado del registro
  → Acción (Application/Actions)
      → DB::transaction + lockForUpdate
      → reglas de estado y concurrencia
      → cambio + auditoría + notificaciones
      → job con afterCommit() si hay trabajo lento
  → respuesta JSON
```

## Middleware

| Middleware | Qué hace |
|---|---|
| `auth:sanctum` | Exige sesión. La SPA usa cookie de sesión y token CSRF. |
| `verified` | Exige correo verificado. |
| `active-role` (`RequireActiveRole`) | Exige un rol activo guardado en la sesión. Si falta, responde `409` «Seleccione un rol vigente.». |
| `EnsureActiveUser` | Si la cuenta se desactivó, cierra la sesión y responde `403`. |
| `AssignCorrelationId` | Lee o crea `X-Correlation-ID`, lo agrega al log y a la respuesta. |
| `AddSecurityHeaders` | Agrega cabeceras de seguridad. |
| `throttle:*` | Limita login, recuperación, 2FA, importaciones, firmas, exportaciones y chat de IA. |

## Responsabilidades

- **Controlador** (`Presentation/Http/Controllers`): recibe, llama a la acción y arma
  el JSON. No decide reglas académicas.
- **Form Request** (`Presentation/Http/Requests`): valida forma, tipos y límites.
- **Policy / Gate**: decide si el usuario puede actuar sobre ese registro con su rol
  activo. Las políticas se registran en `AppServiceProvider` (`SyllabusPolicy`,
  `ConvocationPolicy`, `SyllabusProcessPolicy`, `SyllabusRevisionPolicy`,
  `ExportArtifactPolicy`, `UserPolicy`) junto con Gates como `manage-templates`,
  `review-library` y `view-audit`.
- **Acción** (`Application/Actions`): el caso de uso. Abre la transacción, bloquea
  filas, comprueba el estado, escribe, audita y notifica.
- **Modelo** (`Infrastructure/Persistence/Models`): Eloquent con alias de columnas.
  Los modelos de evidencia rechazan cambios (`ImmutableRecord`).
- **Job** (`Infrastructure/Jobs`): trabajo lento después del commit.

## Escrituras críticas

Enviar, aprobar en Coordinación, verificar en Biblioteca, firmar y regenerar siguen
este patrón:

1. bloquear el sílabo (y la revisión o exportación) con `lockForUpdate`;
2. volver a autorizar y comprobar el estado dentro de la transacción;
3. para el borrador, comparar `version_bloqueo` (`silabos.version_borrador`);
4. escribir el cambio y su evidencia inmutable;
5. registrar `eventos_auditoria` y guardar notificaciones;
6. despachar jobs con `afterCommit()`;
7. si la operación se repite (misma clave de solicitud o registro ya creado), devolver
   el resultado existente sin duplicar el efecto.

## Validación

- Forma, tipos y presencia: Form Request.
- Reglas de estado o rol: la acción.
- Reglas de la plantilla (campos obligatorios y reglas de bloque): `ValidateDraft`,
  que se calcula al validar o enviar y no se guarda.
- Integridad estructural: `CHECK`, índices únicos y triggers de PostgreSQL
  (ver [database.md](database.md)).
- La IA nunca valida ni bloquea.

## Respuestas de error

| Código | Cuándo |
|---|---|
| `401` | Sin sesión. |
| `403` | Sin permiso o cuenta desactivada. |
| `409` | Falta el rol activo, o conflicto de versión del borrador: `code = conflicto_version_borrador` y `version_bloqueo_actual`. |
| `419` | Token CSRF vencido. La SPA pide uno nuevo y reintenta una vez. |
| `422` | Datos inválidos o regla de negocio: `message` y `errors` por campo. |

Las rutas `api/*` siempre responden JSON. No se muestran trazas al usuario.

## Lecturas

- Se seleccionan columnas y relaciones explícitas para evitar N+1.
- Los listados largos se paginan en el servidor.
- Los filtros y el orden permitidos se declaran; no se interpolan columnas del cliente.

## Tiempo, IDs y concurrencia

- UUID v7 generados en la aplicación.
- Instantes en UTC; se muestran en `America/Guayaquil` (`DISPLAY_TIMEZONE`).
- El borrador usa bloqueo optimista con `version_bloqueo`. Cada guardado lo incrementa;
  si no coincide, la API responde `409` y la SPA ofrece recargar.

## Contrato

`php artisan openapi:export` escribe `docs/architecture/openapi.json`. El frontend lo
usa en `npm run contract:check`. Si cambias una ruta, regenera el contrato en el mismo
cambio.
