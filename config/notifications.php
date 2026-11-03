<?php

$defaultEvents = ['convocatoria.abierta', 'convocatoria.reanudada', 'silabo.correccion_solicitada', 'silabo.devuelto_borrador', 'silabo.aprobado', 'coordinacion.aprobado', 'silabo.reenviado_biblioteca', 'proceso.reanudado', 'silabo.eliminado', 'silabo.regenerado', 'proceso.plantilla_rehecho'];

// Solo se aceptan tipos de aviso («grupo.nombre»); un valor vacío o equivocado (TRUE, 1…) no apaga nada.
$events = array_values(array_filter(
    array_map('trim', explode(',', (string) env('NOTIFICATION_EMAIL_EVENTS', ''))),
    fn (string $event): bool => preg_match('/^[a-z]+\.[a-z_]+$/', $event) === 1,
));

$time = trim((string) env('NOTIFICATION_DIGEST_TIME', ''));

return [
    /*
    | `digest`: resumen diario para Coordinación (sílabos por revisar), de lunes a
    | viernes a la hora de la institución y solo si hay algo pendiente. Una hora vacía o que no sea
    | «HH:MM» vale 08:00.
    |
    | Avisos que, además de la campana, llegan por correo. Solo los que piden una acción de la
    | persona y ocurren poco; el resto queda en el sistema. Se puede ajustar sin tocar el
    | código con NOTIFICATION_EMAIL_EVENTS (tipos separados por coma; vacía usa la lista por
    | defecto) o apagar con NOTIFICATION_EMAILS=false. La lista definitiva depende de PV-15.
    */
    'digest' => [
        'enabled' => (bool) env('NOTIFICATION_DIGEST', true),
        'time' => preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '08:00',
    ],

    'email' => [
        'enabled' => (bool) env('NOTIFICATION_EMAILS', true),
        'events' => $events === [] ? $defaultEvents : $events,
    ],
];
