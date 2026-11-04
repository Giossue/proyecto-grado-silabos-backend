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

## Tasks

| ID | Specs | Ruta | Estado | Commit |
|---|---|---|---|---|
| T1 | S1, S2, S5–S8, S9 (backend y docs) | delegada: escritor de backend (en paralelo con T2; superficies disjuntas; respaldo de contexto) | pendiente | |
| T2 | S3, S4, S7, S9 (frontend) | delegada: escritor de frontend (en paralelo con T1) | pendiente | |
| T3 | todas | delegada: verificador independiente (ítems de alto riesgo 1 y 4) | pendiente | |

## Log

- L1 (2026-11-03): «mira hay un error de logica, lo notas? … sale abierto por el periodo o por qué? solo esa es mi duda». Explicación: «Abierto» es el estado guardado, no se calcula a partir de las fechas.
- L2 (2026-11-03): «pero a ver, porque vencido? sería finalizado, porque la finalización debe ser automática y claro, la opción de prorroga se mantiene pues».
- L3 (2026-11-03): respuestas: prórroga «También después» (nota: «Creo que lo mejor sería SOLO cuando finalizó automaticamente y claro cuando lo hizo manualmente.»); revisiones «Finalizar igual»; aclaración, reabrir: «Ambas».
- L4: hallazgos del mapa:
  - `ProcessClosing::close` comparte el cierre manual y el automático, toma una fotografía de los sílabos que no están en revisión ni aprobados (`closed_document`), cierra las convocatorias de carrera abiertas o en pausa y no guarda su estado previo.
  - El índice único `convocatorias_universidad_en_curso_unico` impide tener dos convocatorias en curso.
  - El cierre automático (`CloseEndedProcesses`) y el aviso (`NotifyProcessClosingSoon`) se basan en `fecha_fin_periodo`.
  - La prórroga hoy se rechaza si la convocatoria está cerrada (política y acción).
