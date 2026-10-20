# Pendientes

Lo único que falta, en un solo lugar. Cuando algo se resuelve, se borra de aquí y la decisión queda en
`DECISIONS_STATUS.md`.

## Decisiones que debe tomar la UEB

| ID | Decisión | Quién decide | Mientras tanto |
|---|---|---|---|
| PV-01 | Autoridad que emite, aprueba y publica la plantilla | Coordinación o autoridad | Administración edita la plantilla |
| PV-03 | Período de pilotaje y aceptación | Dirección o Coordinación | — |
| PV-04 | Responsable de la aceptación funcional | Dirección o Coordinación | — |
| PV-05 | Cantidad real de docentes, paralelos y sílabos | Coordinación | Datos de prueba |
| PV-07 | Word oficial y reglas de exportación | Autoridad de la plantilla | La plantilla oficial reproduce el sílabo de ejemplo |
| PV-11 | Conservación, respaldo, RPO y RTO | Personal técnico y autoridad | Sin política formal |
| PV-12 | Base legal y aviso de privacidad | UEB | Borrador en `docs/security/aviso-de-privacidad.md` |
| PV-14 | Modelos de IA que se usarán | Autores y evaluación experta | Proveedor configurable por `AI_DRIVER` |
| PV-15 | Correo institucional y contenido de los avisos | Personal técnico y Coordinación | `MAIL_MAILER=log` |
| PV-16 | Edición excepcional del contenido por Coordinación | Coordinación | No se permite |
| PV-17 | Instrumento para medir el proceso actual | Dirección y autores | — |
| PV-18 | Umbrales de utilidad y precisión de la IA | Coordinación y docentes | — |
| PV-19 | Navegadores y dispositivos reales | Personal técnico y usuarios | Probado solo en Chromium de escritorio |
| PV-20 | Línea, sublínea, director, pares y fechas académicas | Integración Curricular | — |

PV-02 (precedencia entre documentos de la carrera) y PV-13 (equipo para IA local) probablemente ya no aplican:
no hay comparación automática de documentos y la IA usa proveedores externos. Falta confirmarlo.

## Firma electrónica (DT-16)

La firma valida la cadena con `SIGNATURE_TRUSTED_CA_BUNDLE`. `resources/trust/ca-ecuador.pem` trae 14 entidades,
pero la variable está vacía en `.env.example`, así que hoy solo se verifica la integridad. La UEB debe confirmar la
lista de entidades y si exige sello de tiempo externo (hoy la hora es la del servidor). Pasos en
`docs/runbooks/firma-electronica-entidades.md`.

## Reglas por confirmar

- **Unidades y horas:** Docencia debe confirmar las reglas que se exigen al enviar:
  - las horas suman exactamente las de la asignatura;
  - hay 16 semanas.

  También debe decir si valen para materias de prácticas. Falta decidir cómo se evalúa una asignatura con 0 horas
  ACD o AA (Vicerrectorado).
- **Motivos de rechazo de Biblioteca:** los cinco motivos de `LibraryCommentReasons::REASONS` están por validar con
  Biblioteca.

## Producción

- **Variables de entorno:**
  - `TRUSTED_PROXIES` vale `*` por defecto (`config/app.php`, `.env.example`); hay que fijar el proxy real;
  - `SESSION_SECURE_COOKIE=true`;
  - `APP_DEBUG=false`.
- **Cola de la importación de la ficha:** `PrepareFichaImportJob` tiene `timeout` de 900 s, pero
  `REDIS_QUEUE_RETRY_AFTER` vale 180 (`.env.example` y `config/queue.php`). Con más de un worker, una importación
  que tarde más de 180 s se entrega otra vez y queda marcada como fallida. Hay que fijar un valor mayor que 900
  (por ejemplo 960) en el servidor y en `.env.example`.
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
  - fidelidad del Word y del PDF frente al documento oficial (PV-07);
  - recorrido completo con los cuatro roles.

## Errores encontrados en el código

Hallazgos de la revisión de documentación del 2026-10-20, todavía sin corregir.

1. **Textos que contradicen al servidor:**
   - `ResetSyllabus` pide «reábralo»;
   - el diálogo de Biblioteca dice que el reenvío vuelve a Coordinación;
   - la guía de Coordinación pide «Programar las materias del período» y menciona una importación de paralelos que
     no existe;
   - el aviso «exportación completada» ofrece descargar antes de las tres firmas.
2. **Permisos que solo controla la pantalla:**
   - «Quitar de la carrera» con la convocatoria abierta;
   - «Devolver al docente» de Biblioteca no revisa el estado de la convocatoria, a diferencia de «Aprobar
     bibliografía».
3. **Restos sin uso:**
   - `idempotency_key` se exige al aprobar en Coordinación y al comentar en Biblioteca, pero no se usa;
   - la función `proteger_datos_entrada_trabajo()` no tiene trigger;
   - el worker escucha `notificaciones` e `integraciones`, que no usa ningún trabajo.
4. **Configuración:**
   - Nginx acepta 32 MB y el PDF firmado admite 40 MB;
   - faltan índices en `definiciones_campo.plantilla_id`, `importaciones_ficha.solicitado_por` y
     `ayudas_pantalla.actualizado_por`;
   - `composer dev` no procesa las colas `documentos` e `ia`;
   - `GOTENBERG_URL` no está en `.env.example`.
5. **Prueba e2e desactualizada:** `tests/e2e/accessibility.spec.ts` (frontend) busca el botón «Nueva plantilla», que
   ya no existe.

## Deuda técnica

| ID | Hallazgo | Acción |
|---|---|---|
| D-01 | Las tablas `sesiones`, `restablecimientos_contrasena` y `migraciones` conservan columnas en inglés: los drivers de Laravel las fijan | Aceptado; solo cambia si se cambia de driver |
| D-02 | Claves en inglés dentro de JSON (`contexto_academico`, `fotografia`, `metadatos` de auditoría) | Traducir solo documentos nuevos con lector doble; nunca reescribir fotografías selladas |
| D-03 | El contrato del gateway de IA (`contract-simulator-v1`) sigue en inglés | Decidir al cerrar PV-14 |
| D-04 | `FixedContent`, `TemplateDocumentDefaults` e `IdentificationCard::document` solo los usan migraciones antiguas y fotografías anteriores a I-229 | Mover su contenido a las migraciones y borrarlos |
| D-05 | Caminos de dibujo para fotografías antiguas (`SyllabusWordDocument::sectionCompact`, `HistoricalFieldDocument`) | Retirar cuando no queden revisiones con esos formatos |
| D-06 | Los motivos de rechazo de Biblioteca están en código (`LibraryCommentReasons`) | Pasarlos a datos si Biblioteca pide cambiarlos |

## Regla de bloqueo

Si una tarea depende de una decisión de esta lista, se usa el valor de «Mientras tanto» o se detiene esa parte.
Nunca se decide en nombre de quien debe decidir.
