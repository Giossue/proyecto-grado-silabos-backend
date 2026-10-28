# Stack tecnológico

## Piezas

| Área | Tecnología |
|---|---|
| Backend | PHP ^8.4 y Laravel 13 (este repositorio) |
| Contrato HTTP | API JSON bajo `/api/v1`, descrita en `docs/architecture/openapi.json` (ADR-0008) |
| Autenticación | Laravel Fortify + Sanctum en modo SPA (cookie de sesión y CSRF) |
| Frontend | SPA Vue 3 + TypeScript + Vite + Vue Router, en `proyecto-grado-silabos-frontend` |
| Componentes | shadcn-vue sobre Reka UI, Tailwind CSS 4, iconos Lucide |
| Editor de documentos | Tiptap 3 (núcleo y extensiones MIT) (ADR-0007) |
| Lienzo de la malla | Vue Flow (ADR-0006) |
| Firma en el navegador | `@signpdf`, `node-forge` y `pdf-lib` |
| Base de datos | PostgreSQL 18 |
| Colas y caché | Redis 8 con Predis; colas `critica`, `documentos` e `ia` |
| Sesiones | Tabla `sesiones` en PostgreSQL |
| Word | PHPWord |
| PDF | Gotenberg (LibreOffice) a partir del Word; sin Gotenberg, PDF de texto simple |
| Lectura de Excel | PhpSpreadsheet y OpenSpout |
| Fichas de carrera | Excel estructurado generado y leído con PhpSpreadsheet |
| Sellos de firma PDF | `pdftotext` (Poppler) para localizar la posición de cada sello |
| IA | Proveedor externo configurable por `AI_DRIVER` (ver [ai-service.md](ai-service.md)) |
| Correo | SMTP institucional en producción; Resend solo en pruebas (`MAIL_MAILER`) |
| Archivos | Disco `private` en `storage/app/private` |
| Despliegue | Contenedores en Dokploy; push a `main` despliega y migra |

## Versiones bloqueadas

Las fijan `composer.lock` y `package-lock.json`. Valores al 2026-10-20:

| Componente | Versión |
|---|---:|
| Laravel | 13.35.0 |
| Fortify / Sanctum | 1.38.0 / 4.3.3 |
| PHPWord | 1.4.0 |
| PhpSpreadsheet / OpenSpout | 5.10.0 / 5.12.0 |
| Predis | 3.5.1 |
| Pest / Larastan (nivel 7) / Pint | 5.1.1 / 3.10.0 / 1.30.5 |
| Imagen PHP del contenedor | `php:8.4-fpm-alpine3.23` |
| Vue / Vue Router | 3.5.42 / 4.6.4 |
| Vite / TypeScript | 8.3.0 / 5.9.3 |
| Tailwind CSS / Reka UI | 4.3.3 / 2.10.4 |
| Tiptap | 3.31.3 |
| Vue Flow core / node-toolbar | 1.48.2 / 1.1.1 |
| Vitest / Playwright | 5.0.1 / 1.63.0 |

No se actualizan dependencias principales de paso dentro de otro cambio.

## Agregar una dependencia

1. Comprueba que el framework o el código actual no resuelven la necesidad.
2. Revisa compatibilidad, licencia, mantenimiento y tamaño.
3. Encapsula el SDK de un proveedor en un adaptador; el dominio no lo conoce.
4. Agrega una prueba del comportamiento que se espera del paquete.
5. Actualiza el lockfile y este documento en el mismo cambio.

## Comandos

```bash
# backend
composer verify          # escaneo de secretos, Pint, PHPStan y pruebas
php artisan openapi:export

# frontend
npm run lint
npm run typecheck
npm test
npm run contract:check   # compara llamadas del cliente con openapi.json
```
