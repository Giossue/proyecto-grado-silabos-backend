<?php

return [
    /*
    | `digest`: resumen diario para Coordinación (sílabos por revisar y por firmar), de lunes a
    | viernes a la hora de la institución y solo si hay algo pendiente.
    |
    | Avisos que, además de la campana, llegan por correo. Solo los que piden una acción de la
    | persona y ocurren poco; el resto queda en el sistema. Se puede ajustar sin tocar el
    | código con NOTIFICATION_EMAIL_EVENTS (tipos separados por coma) o apagar con
    | NOTIFICATION_EMAILS=false. La lista definitiva depende de PV-15.
    */
    'digest' => [
        'enabled' => (bool) env('NOTIFICATION_DIGEST', true),
        'time' => (string) env('NOTIFICATION_DIGEST_TIME', '08:00'),
    ],

    'email' => [
        'enabled' => (bool) env('NOTIFICATION_EMAILS', true),
        'events' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'NOTIFICATION_EMAIL_EVENTS',
            'convocatoria.abierta,silabo.correccion_solicitada,silabo.aprobado',
        ))))),
    ],
];
