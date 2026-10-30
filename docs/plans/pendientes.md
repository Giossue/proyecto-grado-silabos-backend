# Pendientes

Lo único que falta, en un solo lugar. Cuando algo se resuelve, se borra de aquí y la decisión queda en
`DECISIONS_STATUS.md`.

## Decisiones que debe tomar la UEB

| ID | Decisión | Quién decide | Mientras tanto |
|---|---|---|---|
| PV-03 | Período de pilotaje y aceptación | Dirección o Coordinación | — |
| PV-04 | Responsable de la aceptación funcional | Dirección o Coordinación | — |
| PV-05 | Cantidad real de docentes, paralelos y sílabos | Coordinación | Datos de prueba |
| PV-11 | Conservación, respaldo, RPO y RTO | Personal técnico y autoridad | TIC respalda todas las bases a nivel de servidor (2026-10-26); falta confirmar que incluye el volumen `storage/app` y cada cuánto |
| PV-12 | Base legal y aviso de privacidad | UEB | Borrador en `docs/security/aviso-de-privacidad.md` |
| PV-15 | Correo institucional (servidor SMTP, puerto, cuenta y dirección de envío; Resend queda para pruebas hasta la instalación en diciembre) y qué avisos llegan por correo | Personal técnico y Coordinación | El servidor de pruebas envía con Resend desde `no-responder@devs-ueb.tech`; en producción se configura el SMTP de la UEB (`docs/runbooks/deploy-dokploy.md`). Por correo solo llegan al docente la convocatoria abierta, la corrección solicitada y el sílabo aprobado (lista en `NOTIFICATION_EMAIL_EVENTS`), y a Coordinación un resumen diario de lunes a viernes si tiene sílabos por revisar o firmar; Administración y Biblioteca no reciben correo |
| PV-16 | Edición excepcional del contenido por Coordinación | Coordinación | No se permite |
| PV-17 | Instrumento para medir el proceso actual | Dirección y autores | — |
| PV-18 | Umbrales de utilidad y precisión de la IA | Coordinación y docentes | — |
| PV-19 | Navegadores y dispositivos reales | Personal técnico y usuarios | Probado solo en Chromium de escritorio |
| PV-20 | Línea, sublínea, director, pares y fechas académicas | Integración Curricular | — |

PV-02 (precedencia entre documentos de la carrera) y PV-13 (equipo para IA local) probablemente ya no aplican:
no hay comparación automática de documentos y la IA usa proveedores externos. Falta confirmarlo.

## Continuidad del sílabo entre períodos (PV-21)

Pedido: mientras la plantilla no cambie, el docente conserva lo que llenó en el período anterior. Acordado con el
usuario el 2026-10-30; falta implementarlo. Mientras tanto rige la regla actual: cada convocatoria crea los
sílabos desde cero.

- **Qué se conserva:** solo los campos del docente. Lo de Coordinación sale de la ficha actual.
- **Interruptor «El docente puede editarlo»:** se elimina. El docente nunca edita campos de Coordinación.
- **Estado inicial:** Borrador sin firmas ni revisiones; el docente edita encima, sin aviso ni botón para
  descartar.
- **Momento:** al abrir el docente su sílabo (supuesto; el usuario no tiene preferencia).
- **Origen:** el último sílabo del mismo docente en esa asignatura, en un período cerrado. Si cambia de paralelo,
  hereda igual.
- **Sin herencia (empieza de cero):** docente distinto, docente retirado de la carrera y reemplazado, o plantilla
  modificada desde el período de origen.
- **Plantilla:** cualquier cambio corta la herencia. Antes de abrir la convocatoria, y al editar la plantilla con
  la convocatoria en pausa, el botón espera unos segundos con un mensaje claro de lo que implica. Falta definir
  cómo detectar el cambio: la plantilla no tiene versiones (requiere guardar una huella de su estructura).
- **Malla en curso:** horas, créditos o modalidad afectan solo los sílabos de esa asignatura, no los de toda la
  carrera (hoy rehace toda la carrera). Una relación, un código o un nombre afecta solo las asignaturas que lo
  muestran. En ambos casos se conserva lo escrito; si el sílabo estaba enviado o aprobado, vuelve a Borrador para
  reenviarse y firmarse, con correo al docente. Coordinación ve la misma espera de unos segundos antes de guardar.
- **Períodos cerrados:** nunca se modifican.

## Firma electrónica (DT-16)

La firma valida la cadena con `SIGNATURE_TRUSTED_CA_BUNDLE`. `resources/trust/ca-ecuador.pem` trae 14 entidades,
y el servidor de pruebas ya tiene la variable configurada (2026-10-26); en `.env.example` sigue vacía. La UEB debe confirmar la
lista de entidades y si exige sello de tiempo externo (hoy la hora es la del servidor). Pasos en
`docs/runbooks/firma-electronica-entidades.md`.

## Producción

- **Variables de entorno:**
  - `TRUSTED_PROXIES` vale `*` por defecto (`config/app.php`, `.env.example`); el servidor de pruebas confía solo en
    las redes privadas (2026-10-26);
  - `AI_TIMEOUT` debe ser menor que los 60 s del trabajo `AnswerSyllabusChatJob` (el servidor de pruebas usa 50);
  - `DISPLAY_TIMEZONE` fija la fecha de hoy de la institución (por defecto `America/Guayaquil`). Cambiarla
    cambia los plazos de la convocatoria y las fechas ya guardadas.
- **Reloj del servidor:** la fecha de hoy sale del reloj del servidor, así que debe estar sincronizado con NTP.
- **Dependencias:** `npm audit --audit-level=high` y `composer audit` no marcan nada alto (2026-10-27).
  Quedan 4 avisos bajos en herramientas de compilación (`workbox-build`, `vite-plugin-pwa`); no llegan al navegador.
- **Cuenta de demostración:** rotar su clave si se usa en un servidor real.
- **Servidor:** confirmar que la base remota está en la migración más reciente y que hay un worker para la cola `ia`.

## Accesibilidad y aceptación manual

- **Accesibilidad:**
  - errores enlazados a su campo (`aria-describedby`);
  - el foco vuelve a su lugar al cerrar diálogos abiertos desde menús;
  - jerarquía de títulos en las hojas laterales.
- **Revisión manual** con `docs/quality/manual-review-script.md`:
  - teclado y lector de pantalla;
  - contraste, zoom y 360 px;
  - navegadores y dispositivos reales (PV-19);
  - que el Word y el PDF generados se vean igual que la plantilla;
  - recorrido completo con los cuatro roles.

## Deuda técnica

| ID | Hallazgo | Acción |
|---|---|---|
| D-01 | Las tablas `sesiones`, `restablecimientos_contrasena`, `migraciones`, `cola_trabajos`, `datos_temporales` y `bloqueos_temporales` conservan columnas en inglés: los drivers de Laravel las fijan | Aceptado; solo cambia si se cambia de driver |
| D-02 | Claves en inglés dentro de JSON (`contexto_academico`, `fotografia`, `metadatos` de auditoría) | Traducir solo documentos nuevos con lector doble; nunca reescribir fotografías selladas |
| D-03 | El contrato del gateway de IA (`contract-simulator-v1`) sigue en inglés | Traducir al ajustar el gateway para DeepSeek |
| D-04 | `FixedContent`, `TemplateDocumentDefaults` e `IdentificationCard::document` solo los usan migraciones antiguas y fotografías anteriores a I-229 | Mover su contenido a las migraciones y borrarlos |
| D-05 | Caminos de dibujo para fotografías antiguas (`SyllabusWordDocument::sectionCompact`, `HistoricalFieldDocument`) | Retirar cuando no queden revisiones con esos formatos |
| D-06 | Los motivos de rechazo de Biblioteca están en código (`LibraryCommentReasons`) | Pasarlos a datos si Biblioteca pide cambiarlos |

## Regla de bloqueo

Si una tarea depende de una decisión de esta lista, se usa el valor de «Mientras tanto» o se detiene esa parte.
Nunca se decide en nombre de quien debe decidir.
