# Finalización automática al vencer la entrega y prórroga que reabre

Objetivo: la convocatoria institucional se finaliza sola al vencer la entrega, y una prórroga puede reabrirla.
Rama: main, con commits por unidad y sin push. Entrega: backend y frontend. Pruebas: Pest (`php artisan test --parallel --recreate-databases`) y Vitest (`npm test`).

## Specs

- S1: «pero a ver, porque vencido? sería finalizado, porque la finalización debe ser automática y claro, la opción de prorroga se mantiene pues». La convocatoria institucional (abierta o en pausa) se finaliza sola el día siguiente a su fecha de entrega (`fecha_entrega`, día de la institución). Hoy lo hace el día siguiente al último día de clases. La finalización automática tiene los mismos efectos que «Finalizar».
- S2: Revisiones: «Finalizar igual». Se finaliza aunque haya sílabos «En revisión» o «Corrección solicitada»; esos sílabos quedan como estén.
- S3: Prórroga: «También después» y, para reabrir, «Ambas». «Prorrogar plazo» también se puede usar sobre una convocatoria finalizada, tanto si se finalizó sola como si la finalizó Administración, y eso la vuelve a abrir. Se mantienen los límites actuales: como máximo 2 prórrogas, una fecha posterior a la entrega vigente, no en el pasado y sin pasar del último día de clases.
- S4: La etiqueta del estado `cerrado` en la interfaz es «Finalizado»; el filtro es «Finalizados».
- S5 (decisión técnica derivada; se le informa al usuario): no se puede reabrir si hay otra convocatoria institucional abierta o en pausa. El motivo aparece en `extension_block_reason`: «Hay otra convocatoria en curso; finalícela o elimínela antes de reabrir esta».
- S6 (decisión técnica derivada): al finalizar se guarda el estado previo de cada convocatoria de carrera (abierta o en pausa) y la fecha de finalización. Al reabrir, cada convocatoria de carrera que se cerró vuelve a su estado previo, el proceso vuelve a «abierto» y se descarta la fotografía `closed_document` de los sílabos, que vuelven a verse en vivo.
- S7 (decisión técnica derivada): si la plantilla cambió después de la finalización, reabrir con una prórroga pide confirmación. Usa la misma respuesta 422 `code: plantilla_cambiada` con `counts` y el campo `confirmar_rehacer` que la reanudación, y luego adapta los sílabos con `RegenerateProcessSyllabi::regenerate`.
- S8: el aviso de cierre próximo (`proceso.cierre_proximo`) se envía 3 días antes de la fecha de entrega (no del último día de clases). Su texto menciona la entrega, y la deduplicación usa la fecha de entrega, así que una prórroga genera un aviso nuevo.
- S9: se actualizan los textos que dicen que finalizar «no se deshace», que el cierre llega después del último día de clases o que la prórroga solo existe en abierta o pausada: la interfaz, la ayuda (ScreenHelpCatalog), las descripciones de los comandos y la documentación (syllabus-lifecycle, DECISIONS_STATUS, screens, AGENTS, deployment, deploy-dokploy, traceability).
- S10: «necesito que mientras que admin no abra una nueva convocatoria, se siga tomando como si fuera aún la anterior o más bien, tomando en cuenta la finalización de clases, porque ESO es el periodo académico».
  - Una convocatoria finalizada sigue vigente mientras no haya terminado el último día de clases de su período (`fecha_fin`, día de la institución) y Administración no haya creado otra convocatoria institucional después de ella.
  - Mientras siga vigente, los cambios de malla, del proyecto de carrera, de paralelos y de docentes se aplican a sus sílabos con las mismas reglas y confirmaciones que con la convocatoria abierta o en pausa: actualización de la identificación, sílabos enviados o aprobados que vuelven a borrador, traspaso de coordinación y paralelos nuevos con sílabo.
  - En los sílabos sin enviar de esa convocatoria se rehace la fotografía `closed_document`, para que la vista de consulta muestre los datos vigentes.
  - Pasado el último día de clases, o cuando ya existe una convocatoria más nueva, ya no se tocan.
- S11: «el punto 3 arreglalo como tu lo mencionas … si ves que hay un botón flotante? el que dice Todo Listo, por qué no usamos eso? y claro funcionado con lo que dices».
  - Si la plantilla no se puede usar (la misma comprobación que hace el servidor al abrir, reanudar o reabrir), `extension_block_reason` lo dice y remite a «Antes de empezar».
  - El paso «Armar la plantilla del sílabo» de la lista flotante «Antes de empezar» usa esa misma comprobación: si la plantilla no es usable, la lista deja de mostrar «Todo listo» y señala el paso pendiente.
- S12 (reemplaza a S10, 2026-11-04): «todo está guardado en los periodo, si un admin hizo ese chiste, A MENOS que haga prorroga no habrá afectaciones, y se aplicará el siguiente periodo o convocatoria, pero claro, lo recomendable es avisarle de que no haga eso». Alcance: «Para todo».
  - Con la convocatoria finalizada, los cambios de facultad, carrera, malla, proyecto de carrera, paralelos, docentes y coordinador no tocan sus sílabos: ni el estado, ni el contenido, ni `closed_document`. Cambiar un docente tampoco borra un sílabo aprobado.
  - Al reabrir con una prórroga, todo se pone al día: identificación, proyecto de carrera, docentes, coordinador y paralelos nuevos. Si un sílabo enviado o aprobado debe volver a Borrador, pide confirmación antes de reabrir.
  - Si no se reabre, los cambios valen para la próxima convocatoria.
  - Al guardar uno de esos cambios con una convocatoria finalizada que aún podría reabrirse, sale este aviso: «La convocatoria {período} ya finalizó. Este cambio no afecta sus sílabos: conservan los datos que tenían. Se aplicará en la próxima convocatoria, o en esta si la reabre con una prórroga. Evite estos cambios hasta abrir la próxima convocatoria, salvo que sea necesario.» Con «Guardar de todos modos» y «Cancelar».

## Tasks

| ID | Specs | Ruta | Estado | Commit |
|---|---|---|---|---|
| T1 | S1, S2, S5–S8, S9 (backend y docs) | delegada: escritor de backend (en paralelo con T2; superficies disjuntas; respaldo de contexto) | hecha: 707 pruebas, Pint y PHPStan | 7ace4c98, ba088228 |
| T2 | S3, S4, S7, S9 (frontend) | delegada: escritor de frontend (en paralelo con T1) | hecha: 749 pruebas, typecheck, lint y contrato | frontend eed4757 |
| T4 | S10 | delegada: escritor de backend (vigencia hasta el fin de clases; en paralelo con T5) | hecha: 721 pruebas; verificador PASS | 028b0533 |
| T5 | S11 | delegada: escritor de backend y frontend (plantilla usable en el motivo y en «Antes de empezar»; en paralelo con T4) | hecha: verificador PASS | 586b59ae |
| T6 | S12 | delegada: escritor de backend y frontend (respaldo de contexto) | pendiente | |
| T7 | S12 | delegada: verificador independiente (ítems 1 y 4) | pendiente | |
| T3 | todas | delegada: verificador independiente (ítems de alto riesgo 1 y 4) | hecha: PASS en S1–S9, sin bloqueos | — |

## Log

- L1 (2026-11-03): «mira hay un error de logica, lo notas? … sale abierto por el periodo o por qué? solo esa es mi duda». Explicación: «Abierto» es el estado guardado, no se calcula a partir de las fechas.
- L2 (2026-11-03): «pero a ver, porque vencido? sería finalizado, porque la finalización debe ser automática y claro, la opción de prorroga se mantiene pues».
- L3 (2026-11-03): respuestas: prórroga «También después» (nota: «Creo que lo mejor sería SOLO cuando finalizó automaticamente y claro cuando lo hizo manualmente.»); revisiones «Finalizar igual»; aclaración, reabrir: «Ambas».
- L4: hallazgos del mapa:
  - `ProcessClosing::close` comparte el cierre manual y el automático, toma una fotografía de los sílabos que no están en revisión ni aprobados (`closed_document`), cierra las convocatorias de carrera abiertas o en pausa y no guarda su estado previo.
  - El índice único `convocatorias_universidad_en_curso_unico` impide tener dos convocatorias en curso.
  - El cierre automático (`CloseEndedProcesses`) y el aviso (`NotifyProcessClosingSoon`) se basan en `fecha_fin_periodo`.
  - La prórroga hoy se rechaza si la convocatoria está cerrada (política y acción).
- L5 (2026-11-03): decisiones del escritor de backend:
  - La comparación de la plantilla usa `pausado_en ?? finalizada_en`.
  - La migración rellena los datos desde la auditoría.
  - Hay CHECKs en las columnas nuevas.
  - Migración aplicada en la base local.
  - Límites documentados: los cambios de malla o del proyecto de carrera hechos mientras está finalizada no se aplican al reabrir; la carrera simultánea con una creación devuelve 500 por el índice único.
- L6 (2026-11-03): veredicto del verificador: PASS en S1–S9, sin bloqueos. Avisos:
  - A1: una creación o reapertura simultánea devuelve 500 por el índice único (igual que antes).
  - A2: un `confirmar_rehacer` que no es booleano ahora responde 422.
  - A3: `extension_block_reason` no incluye que la plantilla no sea usable.
  - A4: los datos antiguos sin auditoría toman «abierta» por defecto.
  - A5: los cambios hechos con la convocatoria finalizada no se reconcilian al reabrir.
  - A6: la lista se refresca tras cerrar el diálogo y cambia el texto del banner y del detalle de Coordinación.
- L7 (2026-11-03): «Ok, es que eso no debe pasar, porque hasta que no finalice totalmente una convocatoria no debería pasar eso que mencionas, necesito que mientras que admin no abra una nueva convocatoria, se siga tomando como si fuera aún la anterior o más bien, tomando en cuenta la finalización de clases, porque ESO es el periodo académico. y el punto 3 arreglalo como tu lo mencionas, y no creo que del todo, si ves que hay un botón flotante? el que dice Todo Listo, por qué no usamos eso? y claro funcionado con lo que dices».
- L8 (2026-11-03): veredicto del verificador sobre S10 y S11: PASS, sin bloqueos. Avisos:
  - A1: dos períodos que empiezan el mismo día cuentan ambos como vigentes.
  - A2: puede haber un interbloqueo poco probable entre una regeneración y una reapertura simultáneas (500 sin corrupción).
  - A3: una fotografía nueva toma la plantilla actual.
  - A4: con la convocatoria finalizada, un cambio de docente o un traspaso deja sílabos sin poder editarse hasta reabrir.
  - A5: la comprobación de la plantilla se hace en cada carga del panel.
  - Decisión del escritor: `skipIncomplete` al reabrir.
- L9 (2026-11-04): «Es que a ver, todo está guardado en los periodo, si un admin hizo ese chiste, A MENOS que haga prorroga no habrá afectaciones, y se aplicará el siguiente periodo o convocatoria, pero claro, lo recomendable es avisarle de que no haga eso». Respuesta: «Para todo». Confirmado con el ejemplo del nombre de la carrera: «ok mejor».
