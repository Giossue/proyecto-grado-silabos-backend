# Auditoría de la base de datos

- **Base revisada:** remota (`187.127.6.234:8004/silabos_ueb_db`), solo lectura, 2026-09-30.
- **Estado:** al día, hasta la migración `000165`. 45 tablas, 27 triggers, 20 funciones.
- **Cómo se hizo:** esquema real (`pg_dump --schema-only`) + cuántos datos tiene cada columna + búsqueda de uso en backend y frontend + comparación con `docs/architecture/database.md`.

**Resumen:** no hay nada roto de forma grave. Lo que quedó sin tratar son **restos de funciones retiradas** (sobre todo IA vectorial y revisor de IA), **columnas que se escriben y nadie lee**, **datos guardados dos veces**, **reglas de la base viejas o vacías** y **nombres antiguos**. Hay además **1 error real de código** (filtro de auditoría).

Severidad: 🔴 alta · 🟠 media · 🟢 baja.

## Estado (2026-09-30)

Corregido en código y en la base **local** (migraciones `000166`–`000170`, plan
`docs/plans/active/I-168-auditoria-y-limpieza-de-la-base.md`). **Falta aplicarlo en la
remota.**

- ✅ Corregidos: 1–18, 21–25, 27–42, 44–48.
- ➖ Se conservan a propósito (documentado): 19 (`aprobacion_base_id`, trazabilidad),
  20 (descripción de sección, se muestra al docente), 26 (`revision_aprobada_id`,
  protegido por trigger).
- ➖ No se hizo: 43 (renombrar la tabla `fuentes_academicas`): cambio amplio y de poco
  valor; queda como nombre histórico documentado.
- 24: el contexto académico del sílabo es una lectura sincronizada; se corrigió la
  documentación, no el código.

---

## 1. Errores reales (arreglar ya)

| # | Dónde | Qué pasa | Qué hacer |
|---|---|---|---|
| 1 | `eventos_auditoria` — filtro por resultado | El backend acepta `success/failed/denied` (`ViewAuditEventsRequest.php:19`), pero en la base se guarda `exito/fallido/denegado`. **Filtrar por resultado siempre devuelve vacío.** | Cambiar la regla a `exito,fallido,denegado`. |
| 2 | `config/queue.php`, `config/cache.php` | Si falta la variable en `.env`, usan tablas que ya no existen (`cola_trabajos`, `lotes_trabajos`, `cache`). Hoy funciona solo porque `.env` dice `redis`. | Poner `redis` como valor por defecto. |

---

## 2. Tablas y funciones muertas (restos de cosas retiradas)

| # | Sev. | Tabla / objeto | Qué pasa | Qué hacer |
|---|---|---|---|---|
| 3 | 🔴 | `documentos_ia` | Búsqueda vectorial retirada en I-146. 0 filas, **nadie escribe**. Quedan: columna `embedding vector(1536)`, índices HNSW y GIN, trigger `eliminar_documentos_ia_de_fuente`, modelo `AiDocument`, job `EmbedAcademicSourceJob`, gateways de embeddings (código que nadie llama). | Eliminar tabla, trigger, función y código. Ver si la extensión `vector` sigue haciendo falta. |
| 4 | 🟠 | `historial_ia` | Solo se usan los tipos `mensaje` y `ocultamiento`. Quedan del revisor retirado: tipos `analisis/revision/decision`, sesiones `campo:`/`revision`, 4 índices únicos sin uso, `AnalyzeSyllabusFieldJob` (solo lo usan tests). Estados mezclan `completada/completado`, `fallida/fallido`. | Reducir las reglas a lo vigente, borrar el job y unificar los estados. La tabla está vacía. |
| 5 | 🟠 | `bases_resultados_asignatura`, `resultados_aprendizaje_asignatura` | Reemplazadas por `valores_curriculares` (I-147). 0 filas, nadie escribe, solo lecturas sueltas. | Eliminar ambas tablas, sus modelos y lecturas. |
| 6 | 🟠 | Trigger `validar_solicitud_correccion` | Se ejecuta en cada corrección pero **no valida nada**: su rama se quitó en la migración `000102` y el trigger quedó. | `DROP TRIGGER`. |
| 7 | 🟢 | Función `validar_artefacto_exportacion()` | Variable `plantilla_silabo` sin uso y mensaje que habla de "plantilla" (ya no se guarda). Repite lo que ya hace la FK. | Dejar solo la inmutabilidad y corregir el mensaje. |
| 8 | 🟢 | `plantilla_silabo.revision_configuracion` + 4 triggers `tocar_revision_*` | Se incrementa en cada cambio de la plantilla y **nadie la lee**. La doc menciona `revision_plantilla_origen`, que no existe. Dos triggers se llaman igual (`tocar_revision_por_campo`). | Eliminar la columna, los triggers y las funciones. |
| 9 | 🟢 | `Convocation::sources()` | Relación de código que nadie llama. | Eliminar. |
| 10 | 🟢 | Fila sobrante en `migraciones` | 131 filas y 130 archivos: `2026_08_14_000009_create_institutional_import_tables` fue borrado del repo. | Borrar la fila o dejarla. |

---

## 3. Columnas que se escriben y nadie lee

| # | Sev. | Columna | Qué hacer |
|---|---|---|---|
| 11 | 🟠 | `definiciones_campo.ia_habilitada` | Eliminar (y `ai_enabled` de los requests). |
| 12 | 🟠 | `definiciones_campo.marcador_documento` | Siempre vacía. Si `required_markers` tuviera algo, **bloquearía todos los envíos**. Eliminar la columna, el chequeo y `document_marker` de la API. |
| 13 | 🟠 | `valores_campo.heredado` | Todas las lecturas usan `definiciones_campo.heredado`. Eliminar. |
| 14 | 🟠 | `valores_campo.origen_valor_campo` | Copia de `definiciones_campo.origen_maestro`. Eliminar y leer de la definición. |
| 15 | 🟠 | `fuentes_academicas.contenido_fuente_academica` | Era la entrada del índice vectorial. Eliminar. |
| 16 | 🟢 | `fuentes_academicas.version_documento_fuente` | Siempre 1. Eliminar. |
| 17 | 🟢 | `fuentes_academicas.descripcion_fuente_academica` | Solo se escribe (dudoso). Eliminar. |
| 18 | 🟢 | `artefactos_exportacion.asignacion_rol_id` | El dato ya está en `eventos_auditoria`. Candidata a eliminar. |
| 19 | 🟢 | `aprobaciones.aprobacion_base_id` | Solo la lee un test. Conservar como trazabilidad o eliminar la relación. |
| 20 | 🟢 | `secciones_plantilla.descripcion_seccion_plantilla` | Siempre vacía; se muestra al docente si existe. Decidir si se mantiene. |

---

## 4. Datos guardados dos veces (normalización)

| # | Sev. | Qué | Qué hacer |
|---|---|---|---|
| 21 | 🟠 | **Campos de la plantilla:** clave, nombre, tipo, opciones y condición están en el JSON de `campos_plantilla` **y** en `definiciones_campo`. El frontend lee uno y el backend el otro. | Una sola fuente: el JSON solo con la clave, el resto en `definiciones_campo`. Cambio grande (editor, fotografías, Word). |
| 22 | 🟠 | `plantilla_id` en `campos_plantilla` y `definiciones_campo` | Se deduce de la sección. Nada impide que no coincida. Mientras exista: FK compuesta. Quitarla obliga a rehacer triggers y restricciones únicas. |
| 23 | 🟠 | `definiciones_campo.mostrar_si` apunta a otro campo por clave | Sin FK: si el otro campo se borra o renombra, la condición queda huérfana. Pasar a una columna con el ID del otro campo. |
| 24 | 🟠 | `silabos.contexto_academico_silabo` | La doc dice "fotografía fija", pero el código la reescribe. Además guarda nombre y código de la asignatura, que ya salen del paralelo. Decidir si es caché o fotografía y corregir código o doc. |
| 25 | 🟢 | `valores_curriculares.carrera_id` | Se deduce de la asignatura; está bien protegida. La FK simple sobra. |
| 26 | 🟢 | `reaperturas.revision_aprobada_id` | Se deduce de `aprobacion_id`; un trigger las mantiene iguales. Se puede dejar. |
| 27 | 🟢 | `SaveTemplateDocument` guarda campos "desenganchados" en `configuracion.detached_fields` | Otra copia más en JSON. Se resuelve junto con el punto 21. |

---

## 5. Reglas que faltan en la base (integridad)

| # | Sev. | Qué | Qué hacer |
|---|---|---|---|
| 28 | 🟠 | `firmas_documento` | No está en la doc y **no tiene el trigger de "solo agregar"** que sí tienen las demás tablas de evidencia. | Documentarla y agregar el trigger. |
| 29 | 🟠 | `asignaturas.modalidad_asignatura`, `carreras.modalidad_carrera` | Sin CHECK; los valores permitidos solo están en PHP. | Agregar el CHECK (presencial, semipresencial, en_linea, a_distancia, hibrida). |
| 30 | 🟠 | `requisitos_asignatura.tipo_requisito_asignatura` | Sin CHECK. Dudoso: se permite que un mismo par sea a la vez pre y correquisito. | Agregar el CHECK y revisar la unique. |
| 31 | 🟢 | `definiciones_campo`: si es heredado, debe tener origen y no ser editable | Solo se revisa en PHP. | Agregar el CHECK. |
| 32 | 🟢 | `asignaciones_rol.asignado_en` admite vacío | Hacerla `NOT NULL` con fecha por defecto. |
| 33 | 🟢 | Estado `en_cuarentena` (`objetos_almacenados`) y resultado `denegado` (`eventos_auditoria`) permitidos pero nunca usados | Quitarlos del CHECK o empezar a usarlos. |

---

## 6. Índices que sobran

| # | Índice | Motivo |
|---|---|---|
| 34 | `importaciones_curriculares_carrera_id_index` | Ya lo cubren otros dos. |
| 35 | Índices sobre un solo sí/no: `asignatura_activa`, `carrera_activa`, `facultad_activa`, `programacion_asignatura_activa`, `fuentes_academicas_activa` | Con dos valores casi no ayudan. |
| 36 | `valores_curriculares_carrera_id_index` | La consulta real usaría mejor `(concepto_id, carrera_id)`. |
| 37 | `campanias_carrera_id_estado_index`, `procesos_silabos_estado_idx` | Dudosos, poco útiles. |
| 38 | Vista `docentes_vigentes_silabo` con `DISTINCT` | Probablemente sobra. |

---

## 7. Nombres antiguos o inconsistentes (cosmético)

| # | Qué |
|---|---|
| 39 | Restricciones e índices con nombres viejos: `campanias_*`, `procesos_silabos_*`, `plantillas_silabo_*`, `asignaciones_docente_*`, `paralelos_oferta_academica_id_not_null`, `lock_version_not_null`, `*_snapshot_not_null`, `*_locale_not_null`, `usuarios_name_not_null`, etc. Una sola migración de renombrado. |
| 40 | `convocatorias_carreras.proceso_id` apunta a `convocatorias_universidad`; `silabos.convocatoria_id` no dice a cuál de las dos convocatorias apunta. |
| 41 | Columnas sin la convención de nombre completo: `asignaciones_paralelo.docente_paralelo_activo`, `ejecuciones_validacion.errores_bloqueantes`, `definiciones_campo.heredado/origen_maestro/editable_docente/mostrar_si`. |
| 42 | `envios_biblioteca` y `firmas_documento`: fechas con otra precisión y FKs con sufijo `_fkey` en vez de `_foreign`. |
| 43 | `fuentes_academicas` guarda documentos temporales de importación, no "fuentes académicas". |

---

## 8. La documentación no coincide con la base

| # | Qué |
|---|---|
| 44 | `database.md` menciona la tabla `transiciones_estado`, que no existe. |
| 45 | Menciona `revision_plantilla_origen`, que no existe. |
| 46 | Dice que el tipo del campo se guarda una sola vez y que `definiciones_campo.plantilla_id` es la única referencia a la plantilla: falso (ver 21 y 22). |
| 47 | Presenta `documentos_ia` e `historial_ia` como "histórico que se conserva", pero están vacías. |
| 48 | Usa nombres cortos de modelo en vez de las columnas reales (`contexto_academico`, `fotografia`, `modalidad`, `semanas_lectivas`, `tipo`…). |

---

## Revisado y sin problemas

`usuarios` (2FA en uso vía Fortify), `sesiones`, `notificaciones_internas`, `ejecuciones_trabajo`, `trabajos_fallidos`, `restablecimientos_contrasena`, `invitaciones_activacion`, `facultades`, `periodos_academicos`, `programaciones_asignatura`, `paralelos`, `conceptos_curriculares`, `importaciones_curriculares`, `secciones_plantilla`, `revisiones_silabo`, `observaciones_revision`, `resultados_validacion`, `envios_biblioteca`. Los estados del sílabo en la base coinciden con los 5 vigentes. Las reglas de borrado (`ON DELETE`) son coherentes.

---

## Orden sugerido para limpiar

1. **Errores reales** (1–2): cambios chicos, sin migración.
2. **Restos muertos** (3–10) y **columnas que nadie lee** (11–20): una migración de limpieza. Las tablas remotas afectadas están vacías, así que el riesgo es bajo.
3. **Reglas que faltan** (28–33) e **índices** (34–38): otra migración.
4. **Documentación** (44–48): corregir `database.md`.
5. **Datos duplicados** (21–24): lo más grande; conviene hacerlo junto con el cambio del editor de tablas.
6. **Nombres** (39–43): al final y opcional.
