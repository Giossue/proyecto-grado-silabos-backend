# I-167 — Copiar el sílabo entre paralelos del mismo docente

## Pedido del responsable, 2026-09-29

Cuando un docente tiene la misma asignatura en más de un paralelo, llena un sílabo por
paralelo con el mismo contenido. Se agrega un botón **Copiar desde el paralelo X** para
traer lo que ya escribió en otro paralelo. Cada sílabo sigue su propio envío, revisión y
firmas; no se comparte un único sílabo.

## Reglas

- Solo el docente vigente de ambos sílabos puede copiar.
- Origen y destino: misma convocatoria, misma asignatura programada, distinto paralelo.
- El origen debe haberse iniciado (cualquier estado distinto de «Sin iniciar»).
- El destino debe estar en «Sin iniciar» o «Borrador» y la convocatoria en curso: un
  sílabo ya enviado (en revisión, corrección solicitada o aprobado) no se sobrescribe.
  Si el destino está «Sin iniciar», la copia lo inicia.
- Se copian solo los campos que llena el docente (no heredados, editables, que no son
  cálculo). El destino queda idéntico al origen en esos campos, incluidos los vacíos.
  Los datos del sistema (paralelo, jornada, docentes) siguen siendo los del destino.
- Usa `version_bloqueo` como el autoguardado: si el destino cambió, responde conflicto.
- Una transacción; auditoría `silabo.contenido_copiado` con el sílabo de origen.

## Contrato

- `GET /api/v1/teacher/syllabi/{id}` agrega `copy_sources`: lista de
  `{id, parallel, state}` con los sílabos desde los que se puede copiar; vacía si no
  aplica.
- `POST /api/v1/teacher/syllabi/{id}/copy-from/{source}` con `version_bloqueo`; devuelve
  el sílabo actualizado.

## Interfaz

En el detalle del sílabo del docente, si `copy_sources` no está vacía y el sílabo es
editable, un botón «Copiar desde paralelo X» con confirmación (reemplaza lo escrito).

## Verificación

- Pruebas de backend: copia correcta, destino enviado rechazado, otro docente rechazado,
  otra asignatura rechazada, conflicto de versión, `copy_sources` en el detalle.
- Pruebas frontend del botón.
