# I-163 — Avisos sin enlace y pasos de puesta en marcha al día

## Pedido del responsable, 2026-09-29

1. Revisar todos los avisos: los que no piden hacer nada no deben llevar enlace.
2. Actualizar los pasos de «Puesta en marcha» de cada rol, porque el sistema cambió mucho.

## Mapa de avisos

| Aviso | A quién | Enlace |
| --- | --- | --- |
| Nuevo rol disponible (`usuario.rol_asignado`) | la persona | Selector de rol |
| Cuenta activada (`usuario.activado`) | quien invitó | **Sin enlace** (antes: lista de usuarios) |
| Convocatoria institucional abierta (`proceso.abierto`) | coordinaciones | Convocatorias de la carrera |
| Plazo extendido (`proceso.plazo_extendido`) | coordinaciones y docentes con trabajo | **Sin enlace** |
| Convocatoria institucional pausada / cerrada | coordinaciones y docentes | **Sin enlace** |
| Convocatoria institucional reanudada | coordinaciones y docentes | Mis sílabos / Convocatorias |
| Tiene sílabos por elaborar (`convocatoria.abierta`) | docentes | Mis sílabos |
| Convocatoria pausada (`convocatoria.pausada`) | docentes | **Sin enlace** |
| Convocatoria reanudada | docentes | Mis sílabos |
| Ahora coordina X (`coordinacion.asignada`) | coordinación entrante | Revisiones |
| Su sílabo se generó de nuevo (`silabo.regenerado`) | docente | Mis sílabos |
| Sílabo listo para revisión (enviado / reenviado) | coordinación | La revisión |
| Corrección solicitada, aprobado, reabierto, reiniciado | docente | El sílabo |
| Sílabo listo para su firma (`silabo.firmado_docente`) | coordinación | La revisión (tarjeta de firma) |
| Sílabo firmado (`silabo.firmado`) | docente | El sílabo (PDF firmado) |
| Documentos listos (exportación) | quien pidió | Documentos de la revisión |
| Asistente de IA (ejecución / revisión) | docente | El sílabo |

Regla: `NotificationDestination::INFORMATIONAL_EVENTS` devuelve «sin destino»; la campana
marca leído al tocar y la lista no muestra «Abrir». Sin cambios de esquema ni de API.

## Pasos de puesta en marcha

- Administración: facultades **con logo obligatorio**; cuentas (coordinaciones, y docentes
  también desde Coordinación); plantilla con **Texto libre** y **Contenido de la
  plantilla**; «Abrir la convocatoria institucional» desde la fila del período.
- Coordinación: «Importar los datos del Proyecto de carrera» con la indicación de qué
  falta y desde dónde se sube.
- Docencia: «Abrir cada sílabo» (el borrador se inicia solo), enviar, y un paso nuevo
  **«Firmar electrónicamente el sílabo aprobado»** (firmas «docente» sobre los sílabos
  aprobados). El Panel enlaza ese paso a Mis sílabos.

## Verificación

- Servidor: `NotificationLinksTest` (informativo sin destino, accionable con destino);
  `ApiDashboardTest` con 4 pasos del docente. `composer verify` en verde.
- Pantalla: mapa `setupRoutes.signed`; lint, tipos, pruebas y build en verde.

## Partes de la plantilla (mismo día)

El responsable pidió que **Texto libre** sea solo eso: lo escribe el docente, sin hoja ni
marcas (datos automáticos, Docente, Proyecto de carrera). Esas marcas viven únicamente en
**Contenido de la plantilla**, que es donde van las tablas con datos automáticos y del
Proyecto de carrera.

- [x] El constructor ya no abre la hoja ni el menú Insertar para una parte de Texto libre
      (`isFreeTextPart` en `templateParts.ts`; `editableOnSheet` la excluye). Solo se le
      cambia el nombre y la orientación desde Propiedades.
- [x] «Contenido de la plantilla» pasa a llamarse **«Personalizado»** (pantalla, servidor,
      pruebas y documentación de pantallas). Los planes anteriores conservan el nombre
      con que se decidió cada cosa.
- [x] Menú de Convocatorias: «Conservado por actividad docente» pasa a «No se puede
      eliminar: ya hay sílabos iniciados».

## Cabecera de página

- [x] La cabecera común de las pantallas con menú lateral (`AppSidebarHeader`) ya no
      muestra el título de la página; conserva el botón del menú, el avance de puesta en
      marcha, la campana y el tema. El título sigue estando en cada pantalla.
