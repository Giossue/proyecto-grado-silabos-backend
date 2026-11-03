<?php

namespace App\Modules\Operations\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use RuntimeException;

final class WorkflowNotificationMessages
{
    public const TEMPLATE_REDONE = 'proceso.plantilla_rehecho';

    /** @return array{string, string} */
    public static function for(string $eventType, ?Syllabus $syllabus, ?int $revision, ?string $reason = null): array
    {
        if ($eventType === self::TEMPLATE_REDONE) {
            return [
                'Su sílabo volvió a borrador',
                'El administrador cambió la plantilla. Sus sílabos de este proceso volvieron a borrador y se conservó lo que ya había escrito; revíselos y envíelos otra vez.',
            ];
        }
        if ($syllabus === null) {
            throw new RuntimeException('El aviso necesita un sílabo.');
        }
        $syllabus->loadMissing('parallel.subject:id,nombre');
        $subject = $syllabus->subject->nombre;
        $revisionLabel = $revision === null ? '' : ", revisión {$revision}";
        $reasonLabel = $reason === null || trim($reason) === '' ? '' : ' Motivo: '.trim($reason);

        return match ($eventType) {
            'coordinacion.aprobado' => [
                'Sílabo listo para revisión',
                "Coordinación aprobó el sílabo {$subject}{$revisionLabel}. Falta que biblioteca apruebe la bibliografía.",
            ],
            'silabo.reenviado_biblioteca' => [
                'Sílabo listo para revisión',
                "El docente corrigió el sílabo {$subject}{$revisionLabel} y lo reenvió a biblioteca.",
            ],
            'silabo.correccion_solicitada' => [
                'Corrección solicitada',
                "Se solicitó corregir el sílabo {$subject}{$revisionLabel}. Corríjalo y reenvíelo.",
            ],
            'biblioteca.aprobado' => [
                'Biblioteca aprobó un sílabo',
                "Biblioteca aprobó el sílabo {$subject}{$revisionLabel}. Quedó aprobado y firmado.",
            ],
            'biblioteca.correccion_solicitada' => [
                'Biblioteca pidió una corrección',
                "Biblioteca pidió corregir la bibliografía del sílabo {$subject}{$revisionLabel}. El docente lo corrige y lo reenvía directo a biblioteca.",
            ],
            'silabo.aprobado' => [
                'Sílabo aprobado',
                "Biblioteca aprobó el sílabo {$subject}{$revisionLabel}. Ya puede descargar el PDF firmado.",
            ],
            'silabo.eliminado' => [
                'Su sílabo volvió a empezar',
                "Coordinación eliminó su sílabo aprobado de {$subject}. Complételo de nuevo con la malla y la plantilla actuales.{$reasonLabel}",
            ],
            'silabo.devuelto_borrador' => [
                'Su sílabo volvió a borrador',
                "Coordinación cambió la malla o el proyecto de carrera de {$subject}. Lo que escribió se conserva; revíselo, envíelo y fírmelo de nuevo.",
            ],
            'documento.exportacion_fallida' => [
                'No se pudieron generar los documentos',
                "Falló la generación del PDF del sílabo {$subject}{$revisionLabel}. Revise el servicio de documentos y la cola; luego Coordinación puede generarlos de nuevo desde «Documentos».",
            ],
            default => throw new RuntimeException('Tipo de notificación interna no soportado.'),
        };
    }
}
