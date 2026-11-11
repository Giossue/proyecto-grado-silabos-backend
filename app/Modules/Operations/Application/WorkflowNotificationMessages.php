<?php

namespace App\Modules\Operations\Application;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use RuntimeException;

final class WorkflowNotificationMessages
{
    public const TEMPLATE_REDONE = 'proceso.plantilla_rehecho';

    /**
     * Título y mensaje del aviso, para la campana y el correo. El título dice qué pasó y es el asunto
     * del correo; el mensaje no lo repite: solo agrega qué sílabo y revisión, el motivo y qué hacer.
     *
     * @return array{string, string}
     */
    public static function for(string $eventType, ?Syllabus $syllabus, ?int $revision, ?string $reason = null): array
    {
        if ($eventType === self::TEMPLATE_REDONE) {
            return [
                'Sus sílabos volvieron a borrador',
                'Administración cambió la plantilla de la convocatoria. Se conservó lo que ya había escrito; revíselos y envíelos otra vez.',
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
            'silabo.enviado' => [
                'Sílabo enviado',
                "Revise la bibliografía del sílabo de {$subject}{$revisionLabel}.",
            ],
            'silabo.reenviado_biblioteca' => [
                'Sílabo corregido para revisar',
                "El docente atendió su pedido en el sílabo de {$subject}{$revisionLabel}. Revise de nuevo la bibliografía.",
            ],
            'silabo.correccion_solicitada' => [
                'Corrección solicitada',
                "Revise las observaciones del sílabo de {$subject}{$revisionLabel}, corríjalo y envíelo otra vez.",
            ],
            'biblioteca.verificado' => [
                'Bibliografía verificada por Biblioteca',
                "Revise y apruebe el sílabo de {$subject}{$revisionLabel}.",
            ],
            'silabo.aprobado' => [
                'Sílabo aprobado',
                "Ya puede descargar el PDF firmado del sílabo de {$subject}{$revisionLabel}.",
            ],
            'silabo.eliminado' => [
                'Su sílabo volvió a empezar',
                "Coordinación eliminó el sílabo aprobado de {$subject}; elabórelo con la malla y la plantilla actuales.{$reasonLabel}",
            ],
            'silabo.devuelto_borrador' => [
                'Su sílabo volvió a borrador',
                "Cambiaron datos de la carrera, la malla o el proyecto de carrera que usa el sílabo de {$subject}. Se conservó lo que había escrito; revíselo, envíelo y fírmelo de nuevo.",
            ],
            'documento.exportacion_fallida' => [
                'No se pudieron generar los documentos',
                "Es el PDF del sílabo de {$subject}{$revisionLabel}. Revise el servicio de documentos y la cola; después, Coordinación puede generarlo de nuevo desde «Documentos».",
            ],
            default => throw new RuntimeException('Tipo de notificación interna no soportado.'),
        };
    }
}
