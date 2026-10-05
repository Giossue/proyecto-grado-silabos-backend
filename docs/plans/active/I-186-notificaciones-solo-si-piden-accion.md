# I-186 — Notificaciones: solo lo que pide acción o cambia el trabajo

## Pedido del responsable, 2026-10-04

Había demasiados avisos. Se deja solo lo que le toca hacer a la persona o lo que le cambia su
trabajo, más dos avisos nuevos: el docente se entera de que biblioteca aprobó y el coordinador
se entera de que el decanato no aprobó.

## Avisos que quedan

| Aviso | Le llega a |
|---|---|
| Se abrió la convocatoria de su carrera (`convocatoria.abierta`) | Docente |
| Plazo extendido, solo con trabajo pendiente (`proceso.plazo_extendido`) | Docente |
| Su sílabo se regeneró y perdió el contenido (`silabo.regenerado`) | Docente |
| Corrección solicitada, con el comentario (`silabo.correccion_solicitada`) | Docente |
| Su sílabo fue eliminado o reiniciado (`silabo.eliminado`, `silabo.reiniciado`) | Docente |
| Biblioteca aprobó la bibliografía (`biblioteca.verificado`) | Docente (avance) y coordinador (le toca revisar) |
| El decano aprobó y empieza la firma (`silabo.aprobado`) | Docente |
| Llegó un sílabo para revisar (`silabo.enviado`, `silabo.reenviado`) | Bibliotecario |
| Coordinación aprobó (`silabo.revisado_coordinacion`) | Decano |
| El decanato devolvió el sílabo (`silabo.devuelto_decanato`, **nuevo**) | Coordinador |
| Tu turno de firmar (`silabo.firmado_docente`, `_coordinacion`, `_biblioteca`) | Coordinador, bibliotecario y decano |

## Avisos que se retiran

Convocatoria institucional abierta, pausada, reanudada o cerrada; convocatoria de carrera pausada o
reanudada; plazo extendido para el coordinador; decano aprobó para el coordinador; documento
firmado por el decanato; rol asignado; coordinación asignada; cuenta activada.

## Pendiente (no incluido)

Entregar cada aviso sin registrar un trabajo por cada uno en `trabajos_en_cola`.

## Verificación

Suite completa (329), Pint y PHPStan. Prueba nueva del aviso al coordinador cuando el decano devuelve.
