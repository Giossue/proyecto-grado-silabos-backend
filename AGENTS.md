# Guía de agentes — Sílabos UEB

## Base de producción de este repositorio

La base de Sílabos confirmada por el usuario es `silabos_ueb_db`. Verificar también
las tablas `silabos`, `usuarios` y `migraciones` antes de operar. `calidad_software`
pertenece a otro sistema; no usarla para las tareas de este repositorio. Las
credenciales se consumen solo en memoria desde `.pgpass` y nunca se muestran.

## Proyecto

Sistema web para elaborar, revisar, aprobar y firmar los sílabos de la Universidad
Estatal de Bolívar. Admite varias facultades y carreras.

Stack:

- Laravel 13 con PHP 8.4 o superior (`composer.json`).
- API JSON bajo `/api/v1`; este repositorio no tiene pantallas (ADR-0008).
- SPA Vue 3 + TypeScript en el repositorio Git independiente `proyecto-grado-silabos-frontend`,
  que también puede estar dentro de este directorio.
- PostgreSQL como única fuente de verdad.
- Colas (`critica`, `documentos`, `ia`, `general`), caché y bloqueos en PostgreSQL con los drivers
  `database` de Laravel; no hay Redis.
- Archivos en un disco privado.
- Gotenberg convierte el Word del sílabo en PDF.
- IA con un proveedor externo configurable (`AI_DRIVER`, apagado por defecto) para el
  chat. El proyecto de carrera se importa exclusivamente con una plantilla Excel generada desde los
  campos actuales de Coordinación, sin OCR ni extracción por IA.
- Despliegue en Dokploy: un push a `main` despliega y ejecuta las migraciones.

## Lee antes de trabajar

Siempre:

- Decisiones vigentes: `DECISIONS_STATUS.md`.
- Lo que falta decidir o hacer: `docs/plans/pendientes.md`.
- Mapa técnico: `ARCHITECTURE.md` y `docs/architecture/index.md`.
- Índice completo: `docs/README.md`.

Según la tarea:

| Tarea | Lee |
|---|---|
| Reglas del producto | `docs/product/overview.md`, `domain-model.md`, `syllabus-lifecycle.md`, `roles-and-permissions.md` |
| Plantilla del sílabo | `docs/architecture/template-json.md`, `docs/architecture/adr/0011-template-as-data.md`, `docs/product/syllabus-sections.md` |
| Base de datos | `docs/architecture/database.md`, `docs/generated/database-schema.md` |
| Interfaz | `docs/product/screens.md`, `docs/architecture/frontend.md`, `docs/quality/frontend-checklist.md` |
| IA y proyecto de carrera | `docs/architecture/ai-service.md`, `docs/architecture/ficha-excel.md` |
| Word, PDF y firmas | `docs/architecture/files-and-documents.md`, `docs/runbooks/firma-electronica-entidades.md` |
| Colas | `docs/architecture/queues-and-jobs.md` |
| Despliegue | `docs/architecture/deployment.md`, `docs/runbooks/deploy-dokploy.md`, `docs/runbooks/release-verification.md`, `docs/security/hardening.md` |
| Calidad | `docs/quality/definition-of-done.md`, `testing.md`, `traceability.md` |
| Seguridad | `docs/security/principles.md` |

Abre solo lo que la tarea necesita.

## Precedencia

1. Seguridad, privacidad e invariantes de dominio.
2. Decisiones vigentes de `DECISIONS_STATUS.md` y ADR aceptados.
3. Especificación del producto y criterios de aceptación.
4. El `AGENTS.md` más cercano al archivo modificado.
5. Documento ODD de la tarea (`odd/tasks/`).
6. Convenciones generales.

Si dos fuentes se contradicen, detén esa parte, anota el conflicto y pide resolución.
No elijas en silencio.

## Flujo obligatorio

1. Identifica los IDs `RF`, `RNF`, `RN`, `CU`, `UI` y `CP` relacionados.
2. Revisa si la tarea depende de un punto de `docs/plans/pendientes.md`. Esos puntos
   están `POR VALIDAR`: usa el valor de «Mientras tanto» o detén esa parte.
3. Sigue ODD (ver «Flujo de trabajo: ODD»). Si la tarea es grande, crea su documento en
   `odd/tasks/` antes de escribir código.
4. Implementa la unidad vertical más pequeña: política, caso de uso, persistencia,
   interfaz, pruebas y documentación.
5. Ejecuta las verificaciones que correspondan.
6. Si cambia el comportamiento visible, actualiza `docs/quality/traceability.md`.
7. Al terminar, borra el documento ODD (git lo guarda) y su copia en Engram, anota la decisión en
   `DECISIONS_STATUS.md` y quita de `pendientes.md` lo que quedó resuelto.
8. No declares terminado mientras código, migraciones, pruebas y documentación difieran.

Un pendiente no relacionado no impide avanzar. Un pendiente que cambie permisos,
estados, datos, infraestructura o aceptación sí bloquea esa parte.

## Flujo de trabajo: ODD

Se trabaja con ODD (*Organic Driven Development*, de Gentleman Programming); SDD no se usa.
Las reglas generales de ODD llegan con gentle-ai en el `CLAUDE.md` global. En este
repositorio:

- Un cambio pequeño y entendido no deja documento. Uno grande, el que no se podría retomar
  en otra sesión sin notas, lleva un solo documento `odd/tasks/<feature>.md` con encabezado,
  `## Specs` (S1…, con los RF/CU/RN que toca), `## Tasks` (ID, specs, ruta, estado, commit)
  y `## Log` (L1 es el pedido del usuario, textual).
- Copia en Engram con el tema `odd/<feature>/tasks`. Si Engram falla, manda el archivo.
- Se trabaja en `main`, sin rama de feature. Al terminar: commit en `main`, sin push; el
  usuario decide cuándo subir (el push despliega). Este punto reemplaza la regla de ODD de ramas.
- Lo que estaba en `POR VALIDAR` sigue así en el documento; ODD no lo vuelve regla.

## Verificación

Backend, con PostgreSQL activo (`docker compose up -d`):

```bash
composer verify
```

Revisa secretos en el repositorio, Pint, PHPStan nivel 7 y las pruebas Pest. Los
comandos sueltos (`composer lint:check`, `composer types:check`, `php artisan test`)
sirven para diagnosticar.

Frontend, en su repositorio: `npm run typecheck`, `npm run lint`, `npm test` y
`npm run contract:check`. Usa npm; no mezcles gestores ni lockfiles.

La verificación debe ser proporcional al cambio. En ajustes pequeños y mecánicos, edita
y ejecuta solo la comprobación puntual; no lances builds ni la puerta completa ni crees
scripts temporales. Deja `composer verify` para cambios amplios o riesgosos, o cuando se
pida.

## Restricciones de producto

- Roles: Administrador, Coordinador (uno por carrera), Docente y Bibliotecario
  (global). No existe Decanato.
- Estados del sílabo: Sin iniciar, Borrador, En revisión, Corrección solicitada y
  Aprobado. No hay reapertura: un sílabo aprobado solo se elimina y vuelve a empezar.
- Revisión: el docente envía, Coordinación aprueba o pide corrección con un comentario
  general, y Biblioteca revisa la bibliografía (aprueba o devuelve con motivos
  predefinidos). Si Biblioteca devuelve, el reenvío vuelve a Biblioteca.
- Cada uno firma al decidir, en el navegador con su `.p12`: el docente en su primer envío,
  Coordinación al aprobar y Biblioteca al aprobar (queda aprobado). Se firma una declaración
  con la huella del contenido (CMS separada); el titular del certificado debe coincidir con la
  cuenta. Nadie vuelve a firmar en los reenvíos. Al aprobar Biblioteca se genera un solo PDF con
  los sellos de las tres firmas; se descarga apenas existe. No se ofrece Word.
- Una revisión enviada o aprobada no se modifica; congela la definición de la
  plantilla.
- La plantilla es una sola, se edita en el sitio y no tiene versiones. Es solo datos
  (ADR-0011): `plantilla_silabo.definicion_plantilla` más secciones y campos. Cada campo
  dice quién lo llena (`docente`, `coordinacion` o `institucional`).
- El proyecto de carrera de cada asignatura guarda los campos de
  Coordinación en `valores_campo`. Solo Coordinación los edita; el sílabo copia el valor
  al crearse.
- Al abrir su sílabo, el docente hereda sus campos del último sílabo suyo de esa
  asignatura en una convocatoria cerrada, salvo que la plantilla haya cambiado desde
  entonces.
- Cambiar la plantilla con sílabos existentes exige pausar y confirmar; esos sílabos se
  rehacen desde cero, incluso los aprobados. Cambiar la malla o un proyecto de carrera también exige
  pausar, pero conserva lo escrito: solo cambian los sílabos de las materias afectadas y
  los enviados o aprobados vuelven a Borrador tras confirmar. Los procesos cerrados no se
  tocan.
- Cambiar el docente o los paralelos no exige pausa. Un docente nuevo empieza desde cero
  el sílabo de la convocatoria en curso; nada de una convocatoria cerrada se borra.
- La convocatoria se cierra sola el día después del último día de clases (Administración
  también puede finalizarla antes); lo cerrado queda solo para consulta y nunca se elimina. Eliminar una convocatoria abierta o pausada la borra de
  verdad, con todos sus sílabos aunque estén enviados o aprobados, y avisa a los
  involucrados con el mensaje de Administración.
- Las validaciones al enviar y las sugerencias de IA son cosas separadas. La IA propone;
  nunca aprueba, bloquea ni escribe sin que una persona lo confirme. Si falla, solo se
  pierde la ayuda de IA.
- La edición directa del contenido por Coordinación depende de `PV-16`; hoy no existe.
- No hay integración con sistemas institucionales: los datos se cargan a mano o por
  Excel.
- No implementar EVEA, matrículas, notas, horarios ni decisiones académicas
  automáticas.

## Reglas de implementación

- Backend por módulos (`app/Modules/*`) y casos de uso; los controladores no llevan
  lógica de negocio.
- Form Requests para validar y Policies/Gates para autorizar en el servidor.
- Alcance por registro: ocultar un botón no concede ni quita permisos.
- Transiciones de estado y operaciones críticas en acciones de aplicación, dentro de
  transacciones PostgreSQL.
- Esquema en español; la llave primaria se llama como la entidad (`valor_campo_id`).
  UUID generados por la aplicación, fechas `timestamptz` en UTC y restricciones en la
  base para invariantes estructurales. Detalle en `docs/architecture/database.md`.
- No edites migraciones ya aplicadas en un entorno compartido; agrega una nueva.
- Despacha trabajos que dependen de una transacción después del commit.
- Los trabajos de cola son idempotentes (`App\Support\Queue\RunsOnce`). La cola técnica
  (`cola_trabajos`) es transitoria y no guarda trabajos fallidos: cada recurso guarda su
  propio estado.
- Los archivos son privados; las descargas pasan por autorización.
- No registres secretos, documentos completos, prompts sensibles ni datos personales
  innecesarios en logs.
- No edites a mano archivos generados. `docs/architecture/openapi.json` se regenera con
  `php artisan openapi:export` y una prueba exige que coincida con las rutas.
- No escribas comentarios en el código, ni en backend ni en frontend: ni `//`, `#`,
  `/* */`, `<!-- -->` ni docblocks de prosa. Lo que haya que explicar va en `docs/` o en
  el plan. Solo se admiten anotaciones que lee una herramienta: tipos (`@var`, `@param`,
  `@return`, `@property`) y directivas (`@ts-expect-error`, `eslint-disable`,
  `@phpstan-ignore`).

## Trabajo de interfaz

Este repositorio no tiene pantallas. Los cambios de interfaz se hacen en
`proyecto-grado-silabos-frontend`; el backend expone primero un contrato JSON estable y
mantiene `openapi.json` al día. Antes de tocar una pantalla, lee `docs/product/screens.md`,
`docs/architecture/frontend.md` y `docs/quality/frontend-checklist.md`.

## Trabajo con bases de datos

Antes de cambiar el esquema o tocar una base, lee `docs/architecture/database.md`. La
puesta en marcha local está en `README.md`; el despliegue y las migraciones remotas, en
`docs/runbooks/deploy-dokploy.md` y `docs/runbooks/release-verification.md`. No copies
hosts, credenciales ni comandos de un entorno dentro de este archivo.

## Control de versiones

- Cambios pequeños y coherentes.
- Formato de commit: `tipo(alcance): asunto imperativo`, máximo 72 caracteres.
- Al terminar un cambio verificado, haz commit en `main` sin preguntar. No hagas push: lo decide
  el usuario, porque el push despliega. No hagas rebase ni reescribas historia sin petición explícita.
- Nunca confirmes secretos, `.env`, documentos sensibles ni datos de entrevistas sin
  tratamiento y autorización.

## Regla final

Si una decisión no está documentada, no la presentes como requisito confirmado. Anota
el supuesto, su impacto y cómo validarlo.
