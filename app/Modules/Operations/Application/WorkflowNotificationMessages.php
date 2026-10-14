<?php

namespace App\Modules\Operations\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use RuntimeException;

final class WorkflowNotificationMessages
{
    public const TEMPLATE_REDONE = 'proceso.plantilla_rehecho';

    /** @return array{string, string} */
    public static function for(string $eventType, ?Syllabus $syllabus, ?int $revision): array
    {
        if ($eventType === self::TEMPLATE_REDONE) {
            return [
                'El sílabo volvió a empezar',
                'El administrador cambió la plantilla. Su sílabo se rehízo desde cero; vuelva a completarlo.',
            ];
        }
        if ($syllabus === null) {
            throw new RuntimeException('El aviso necesita un sílabo.');
        }
        $syllabus->loadMissing('parallel.subject:id,nombre');
        $subject = $syllabus->subject->nombre;
        $revisionLabel = $revision === null ? '' : ", revisión {$revision}";

        return match ($eventType) {
            'silabo.enviado', 'silabo.reenviado' => [
                'Sílabo listo para revisión',
                "El sílabo {$subject}{$revisionLabel} fue enviado; coordinación lo revisa primero.",
            ],
            'coordinacion.aprobado' => [
                'Sílabo listo para revisión',
                "Coordinación aprobó el sílabo {$subject}{$revisionLabel}. Falta que biblioteca apruebe la bibliografía.",
            ],
            'silabo.reenviado_biblioteca' => [
                'Sílabo corregido para revisión',
                "El docente corrigió el sílabo {$subject}{$revisionLabel} y lo reenvió a biblioteca.",
            ],
            'silabo.correccion_solicitada' => [
                'Corrección solicitada',
                "Se solicitó corregir el sílabo {$subject}{$revisionLabel}. Corríjalo y reenvíelo.",
            ],
            'silabo.aprobado' => [
                'Sílabo aprobado',
                "Biblioteca aprobó el sílabo {$subject}{$revisionLabel}. Se genera el PDF; luego firman docente, coordinación y biblioteca.",
            ],
            'silabo.eliminado' => [
                'Sílabo eliminado',
                "Coordinación eliminó el sílabo aprobado {$subject}. Empiece de nuevo con la malla y la plantilla actuales.",
            ],
            'silabo.firmado_docente' => [
                'Sílabo listo para su firma',
                "El docente firmó electrónicamente el sílabo {$subject}{$revisionLabel}. Falta la firma de coordinación.",
            ],
            'silabo.firmado_coordinacion' => [
                'Sílabo listo para su firma',
                "Docente y coordinación firmaron el sílabo {$subject}{$revisionLabel}. Falta la firma de biblioteca.",
            ],
            'silabo.reiniciado' => [
                'Sílabo reiniciado',
                "Coordinación reinició el sílabo {$subject}. Empiece de nuevo con la malla y la plantilla actuales.",
            ],
            default => throw new RuntimeException('Tipo de notificación interna no soportado.'),
        };
    }
}
