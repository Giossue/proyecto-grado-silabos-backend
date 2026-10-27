# Pendientes

Lo único que falta, en un solo lugar. Cuando algo se resuelve, se borra de aquí y la decisión queda en
`DECISIONS_STATUS.md`.

## Decisiones que debe tomar la UEB

| ID | Decisión | Quién decide | Mientras tanto |
|---|---|---|---|
| PV-03 | Período de pilotaje y aceptación | Dirección o Coordinación | — |
| PV-04 | Responsable de la aceptación funcional | Dirección o Coordinación | — |
| PV-05 | Cantidad real de docentes, paralelos y sílabos | Coordinación | Datos de prueba |
| PV-11 | Conservación, respaldo, RPO y RTO | Personal técnico y autoridad | Sin política formal |
| PV-12 | Base legal y aviso de privacidad | UEB | Borrador en `docs/security/aviso-de-privacidad.md` |
| PV-15 | Correo institucional y contenido de los avisos | Personal técnico y Coordinación | El servidor de pruebas envía con Resend desde `no-responder@devs-ueb.tech` |
| PV-16 | Edición excepcional del contenido por Coordinación | Coordinación | No se permite |
| PV-17 | Instrumento para medir el proceso actual | Dirección y autores | — |
| PV-18 | Umbrales de utilidad y precisión de la IA | Coordinación y docentes | — |
| PV-19 | Navegadores y dispositivos reales | Personal técnico y usuarios | Probado solo en Chromium de escritorio |
| PV-20 | Línea, sublínea, director, pares y fechas académicas | Integración Curricular | — |

PV-02 (precedencia entre documentos de la carrera) y PV-13 (equipo para IA local) probablemente ya no aplican:
no hay comparación automática de documentos y la IA usa proveedores externos. Falta confirmarlo.

## Firma electrónica (DT-16)

La firma valida la cadena con `SIGNATURE_TRUSTED_CA_BUNDLE`. `resources/trust/ca-ecuador.pem` trae 14 entidades,
y el servidor de pruebas ya tiene la variable configurada (2026-10-26); en `.env.example` sigue vacía. La UEB debe confirmar la
lista de entidades y si exige sello de tiempo externo (hoy la hora es la del servidor). Pasos en
`docs/runbooks/firma-electronica-entidades.md`.

## Reglas por confirmar

- **Materias sin sílabo:** el responsable del producto propone (2026-10-26) que la malla marque
  por materia si genera sílabo, en lugar de reglas para materias de prácticas o con 0 horas ACD
  o AA. Falta implementarlo.
- **Motivos de rechazo de Biblioteca:** los cinco motivos de `LibraryCommentReasons::REASONS` están por validar con
  Biblioteca.

## Producción

- **Variables de entorno:**
  - `TRUSTED_PROXIES` vale `*` por defecto (`config/app.php`, `.env.example`) y también en el servidor de pruebas;
    hay que fijar la red del proxy;
  - `AI_TIMEOUT` debe ser menor que los 60 s del trabajo `AnswerSyllabusChatJob` (el servidor de pruebas tiene 60);
  - `DISPLAY_TIMEZONE` fija la fecha de hoy de la institución (por defecto `America/Guayaquil`). Cambiarla
    cambia los plazos de la convocatoria y las fechas ya guardadas.
- **Reloj del servidor:** la fecha de hoy sale del reloj del servidor, así que debe estar sincronizado con NTP.
- **Dependencias:** `npm audit` marca `node-forge` (GHSA-86w9-cpqp-85rv) como alta y sin versión corregida (≤ 1.4.0).
  - **Dónde se usa:** el frontend la usa, directamente y a través de `@signpdf`, para abrir el `.p12` y firmar
    en el navegador.
  - **Por qué el riesgo es bajo:** el fallo está en la verificación de firmas RSA, que la app no usa; las firmas
    las verifica el backend.
  - **Acción:** actualizar cuando salga una versión corregida.
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
| D-01 | Las tablas `sesiones`, `restablecimientos_contrasena` y `migraciones` conservan columnas en inglés: los drivers de Laravel las fijan | Aceptado; solo cambia si se cambia de driver |
| D-02 | Claves en inglés dentro de JSON (`contexto_academico`, `fotografia`, `metadatos` de auditoría) | Traducir solo documentos nuevos con lector doble; nunca reescribir fotografías selladas |
| D-03 | El contrato del gateway de IA (`contract-simulator-v1`) sigue en inglés | Traducir al ajustar el gateway para DeepSeek |
| D-04 | `FixedContent`, `TemplateDocumentDefaults` e `IdentificationCard::document` solo los usan migraciones antiguas y fotografías anteriores a I-229 | Mover su contenido a las migraciones y borrarlos |
| D-05 | Caminos de dibujo para fotografías antiguas (`SyllabusWordDocument::sectionCompact`, `HistoricalFieldDocument`) | Retirar cuando no queden revisiones con esos formatos |
| D-06 | Los motivos de rechazo de Biblioteca están en código (`LibraryCommentReasons`) | Pasarlos a datos si Biblioteca pide cambiarlos |

## Regla de bloqueo

Si una tarea depende de una decisión de esta lista, se usa el valor de «Mientras tanto» o se detiene esa parte.
Nunca se decide en nombre de quien debe decidir.
