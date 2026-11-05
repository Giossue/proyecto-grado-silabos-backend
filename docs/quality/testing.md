# Estrategia de pruebas

## Objetivo

Demostrar comportamiento, autorización, integridad de lo enviado y funcionamiento cuando
falla un servicio externo. La cantidad de pruebas no sustituye la cobertura de riesgos.

## Dónde viven

- **Backend** (este repositorio), con Pest y PHPUnit:
  - `tests/Unit`: reglas puras (semanas del período, nombres y documentos de campo);
  - `tests/Feature/<Módulo>`: rutas JSON, políticas, transacciones y trabajos;
  - `tests/Architecture`: límites de módulos, contrato OpenAPI, protección de la API;
  - `tests/Support`: ayudas compartidas (crear convocatorias, firmar declaraciones, logos).
- **Frontend** (`proyecto-grado-silabos-frontend`):
  - Vitest en `src/**/*.test.ts`;
  - Playwright en `tests/e2e`.

## Comandos

No hay CI. Las verificaciones se corren a mano antes de cada push; un push a `main`
despliega.

Backend:

| Comando | Qué hace |
|---|---|
| `composer verify` | Busca secretos en el repositorio, pasa Pint en modo revisión, Larastan nivel 7 y la suite completa |
| `php artisan test` | Solo la suite |
| `composer verify:restore` | Restaura un dump sintético en un clúster efímero de PostgreSQL |
| `composer benchmark:readiness` | 500 peticiones con concurrencia 50 contra `/health/ready` en loopback |

Frontend:

| Comando | Qué hace |
|---|---|
| `npm run lint` | Prueba las reglas propias de ESLint y luego corre ESLint |
| `npm run typecheck` | `vue-tsc` sin emitir |
| `npm test` | Vitest |
| `npm run contract:check` | Compara las llamadas del cliente con `docs/architecture/openapi.json` del backend |
| `npm run build` | Tipos y build de Vite |
| `npm run test:e2e` | Playwright; exige `VITE_API_URL` con una API local en `127.0.0.1` o `localhost` |

## Base de datos de prueba

La suite usa PostgreSQL, nunca SQLite: UUID, `timestamptz`, constraints de exclusión,
triggers y JSON deben comportarse como en producción. `phpunit.xml` apunta a
`silabos_ueb_test` en `127.0.0.1:55432`, que levanta
`compose.yaml`. Las colas corren en modo `sync`, la caché en `array` y la IA usa el driver `baseline`.

Cada prueba controla sus datos y no depende del orden. `DatabaseBootstrapTest` comprueba
que la conexión use UTC y que el seeder de demostración sea idempotente.

## Qué probar en cada mutación

1. camino feliz;
2. entrada inválida;
3. usuario sin el rol;
4. rol correcto fuera de su carrera;
5. estado que no permite la acción (convocatoria pausada o cerrada, sílabo enviado);
6. recurso inexistente o ajeno;
7. petición repetida;
8. conflicto de versión;
9. rollback a mitad de la operación;
10. evento de auditoría y aviso correctos.

## Tiempo y asincronía

- Congela el reloj para plazos, fechas del período y zona horaria.
- Los fakes prueban el despacho; `JobIdempotencyTest` comprueba que cada trabajo sea
  único y no se solape.
- No uses `sleep` para esperar trabajos: observa el estado del recurso.
- `JobDispatchTest` comprueba que `retry_after` supere el timeout de los trabajos de
  plataforma, exportación, lectura de documentos y extracción de proyectos de carrera.

## Documentos y firmas

- `WordRendererTest` revisa la estructura del Word. `TemplateGoldenWordTest` compara el
  XML del Word de la plantilla oficial con `tests/Fixtures/golden`. Si un cambio es
  intencional, se regenera con `UPDATE_GOLDEN=1`.
- `DocumentOperationsTest` cubre la exportación, la descarga privada y la auditoría.
  Gotenberg se simula con `Http::fake`.
- `DocumentSignatureTest` y `SignatureTrustTest` firman declaraciones de prueba con
  certificados creados por OpenSSL (`tests/Support/SignsStatements.php`): firma al primer
  envío y no al reenviar, firma de Coordinación y Biblioteca al aprobar, titular, cadena
  de confianza, firma inválida o sobre otro texto, declaración ajena, certificado vencido
  y los tres sellos del PDF final. Las demás pruebas que envían o aprueban usan las mismas
  ayudas (`teacherSignature`, `revisionSignature`).

Un archivo válido puede verse cortado o desalineado: la comparación visual con el
documento oficial sigue siendo manual (PV-07).

## IA

La suite pasa sin proveedor real. `TemplateAssistantTest`, `TemplateAssistantApiTest`,
`HostedAiAgentGatewayTest` y `AdministrationDecidesAiTest` cubren el asistente de la plantilla:
propuestas que solo Administración aplica o descarta, servicio caído sin bloquear la edición,
configuración insegura del proveedor y errores sin exponer la clave.
`TeacherChatRemovedTest` comprueba que el chat del docente ya no existe.

`FichaImportTest` conserva los casos de vista previa Excel, aplicación parcial,
comparación con valores actuales, permisos y tablas derivadas de la plantilla.
Los casos existentes reciben la vista previa en la respuesta y reenvían el archivo
al aplicar, sin recuperar un registro de importación.
`FichaFieldValuesTest` cubre encabezados combinados y validación de valores.
Los casos exclusivos del lector OCR, selección de evidencia y extracción por IA se
eliminaron junto a ese código. Las pruebas existentes se adaptaron al contrato Excel;
no se añadieron ni ejecutaron pruebas durante esta limpieza. La aceptación con un
Excel real y el recorrido de usuario siguen pendientes.

## Interfaz

- Vitest cubre páginas y componentes: editor del docente, constructor de plantilla,
  proyecto de carrera, revisión, biblioteca, firmas, avisos y ayuda.
- Playwright recorre la navegación por rol, los componentes y la disposición en anchos
  pequeños. `authentication.spec.ts` y `accessibility.spec.ts` necesitan la API local
  con datos sembrados; las demás interceptan las respuestas HTTP.
- Teclado, lector de pantalla, contraste y dispositivos reales se revisan con
  `manual-review-script.md` (PV-19).

## Rendimiento, seguridad y usabilidad

- La carga usa datos representativos y percentiles, no una petición aislada
  (`performance.md`).
- Seguridad combina pruebas automáticas, revisión de código y casos de abuso.
- La usabilidad se mide con tareas, éxito, tiempo y errores, con un instrumento definido
  (PV-17).

## Trazabilidad

Nombra las pruebas con su caso cuando exista, por ejemplo `test_cp_f_...` o
`it('RNF-029 ...')`. Al cambiar comportamiento, actualiza la fila de
`traceability.md`.
