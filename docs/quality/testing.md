# Estrategia de pruebas

## Objetivo

Demostrar comportamiento, autorización, integridad de lo enviado y funcionamiento cuando
falla un servicio externo. La cantidad de pruebas no sustituye la cobertura de riesgos.

## Dónde viven

- **Backend** (este repositorio), con Pest y PHPUnit:
  - `tests/Unit`: reglas puras (semanas del período, nombres, documentos de campo,
    lector de OpenParser);
  - `tests/Feature/<Módulo>`: rutas JSON, políticas, transacciones y trabajos;
  - `tests/Architecture`: límites de módulos, contrato OpenAPI, protección de la API;
  - `tests/Integration`: Redis real;
  - `tests/Support`: ayudas compartidas (crear convocatorias, firmar PDF, logos).
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
`silabos_ueb_test` en `127.0.0.1:55432` y a Redis en `127.0.0.1:56379`, que levanta
`compose.yaml`. Las colas corren en modo `sync` y la IA usa el driver `baseline`.

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
  plataforma, exportación, chat, lectura de documentos y extracción de fichas.

## Documentos y firmas

- `WordRendererTest` revisa la estructura del Word. `TemplateGoldenWordTest` compara el
  XML del Word de la plantilla oficial con `tests/Fixtures/golden`. Si un cambio es
  intencional, se regenera con `UPDATE_GOLDEN=1`.
- `DocumentOperationsTest` cubre la exportación, la descarga privada y la auditoría.
  Gotenberg se simula con `Http::fake`.
- `DocumentSignatureTest` y `SignatureTrustTest` firman PDF de prueba
  (`tests/Support/SignsPdfIncrementally.php`): orden de firmas, titular, cadena de
  confianza, PDF alterado y certificado vencido. En el frontend, `signPdf.test.ts` y
  `DocumentSignatureCard.test.ts` firman en el navegador.

Un archivo válido puede verse cortado o desalineado: la comparación visual con el
documento oficial sigue siendo manual (PV-07).

## IA

La suite pasa sin proveedor real. `AiAssistanceTest` y `HostedAiChatGatewayTest` cubren:

- alcance del docente y de la carrera, límite de preguntas y de chats;
- contexto congelado de la carrera, la asignatura y el texto del sílabo, sin datos
  personales;
- el chat propone, pero nunca modifica el sílabo;
- citas inventadas, servicio caído y salida inválida sin bloquear la edición;
- configuración insegura del proveedor y errores sin exponer la clave;
- formato de cada proveedor (OpenAI, Claude, DeepSeek).

`FichaImportTest` y `FichaPdfOcrTest` cubren la importación de la ficha: propuesta,
aplicación parcial, permisos, falta de proveedor y lectura de PDF con OpenParser.
También cubren envío al OCR de PDF con texto seleccionable, error sin extracción
local alternativa, OCR no configurado, documentos idénticos,
tablas derivadas de la plantilla y tiempos. `FichaEvidenceTest`, `FichaFieldValuesTest`
y `ExtractFichaProposalTest` cubren continuaciones, separación entre materias y
documentos, índices, variantes de nombre, recuperación de evidencia tardía y conservación
de resultados cuando falla la segunda consulta. Los originales reales eliminados tras
aplicar no forman parte de estas pruebas; su cobertura y tiempo se comparan al volver
a importarlos, sin convertir un ejemplo sintético en una medida de precisión (PV-18).

## Interfaz

- Vitest cubre páginas y componentes: editor del docente, constructor de plantilla,
  ficha, revisión, biblioteca, firmas, avisos y ayuda.
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
