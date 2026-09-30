<?php

namespace App\Modules\Operations\Infrastructure\Jobs;

use App\Models\User;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DeliverInternalNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 300];

    public function __construct(public readonly string $executionId)
    {
        $this->onQueue('notificaciones');
    }

    public function handle(): void
    {
        $execution = DB::transaction(function (): ?JobExecution {
            $locked = $this->lockedExecution();
            if ($locked === null || $locked->estado === 'completada') {
                return null;
            }
            if ($locked->tipo !== 'notificacion.interna') {
                throw new RuntimeException('El trabajo no es una notificación interna.');
            }
            $locked->update([
                'estado' => 'en_ejecucion',
                'intentos' => $locked->intentos + 1,
                'progreso' => 20,
                'iniciado_en' => $locked->iniciado_en ?? now(),
                'finalizado_en' => null,
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);

            return $locked->refresh();
        });
        if ($execution === null) {
            return;
        }

        [$title, $message, $resourceType, $resourceId] = $this->content($execution);
        $recipientIds = $this->recipientIds($execution);
        $eventType = $this->eventType($execution);

        DB::transaction(function () use ($eventType, $message, $recipientIds, $resourceId, $resourceType, $title): void {
            $locked = $this->lockedExecution();
            if ($locked === null || $locked->estado === 'completada') {
                return;
            }
            $activeValues = User::query()
                ->whereIn('id', $recipientIds)
                ->where('activo', true)
                ->pluck('id')
                ->all();
            $activeIds = $this->stringList($activeValues);
            foreach ($activeIds as $recipientId) {
                InternalNotification::query()->firstOrCreate(
                    [
                        'usuario_id' => $recipientId,
                        'clave_deduplicacion' => $this->notificationKey($locked),
                    ],
                    [
                        'tipo' => $eventType,
                        'titulo' => $title,
                        'mensaje' => $message,
                        'tipo_recurso' => $resourceType,
                        'recurso_id' => $resourceId,
                        'notificado_en' => now(),
                    ],
                );
            }
            $locked->update([
                'estado' => 'completada',
                'progreso' => 100,
                'resultado' => ['execution_id' => $locked->id, 'recipient_count' => count($activeIds)],
                'finalizado_en' => now(),
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function (): void {
            $execution = $this->lockedExecution();
            if ($execution === null || $execution->estado === 'completada') {
                return;
            }
            $execution->update([
                'estado' => 'fallida',
                'progreso' => 0,
                'codigo_error' => 'notificacion_interna_fallida',
                'mensaje_error' => 'No fue posible entregar la notificación interna.',
                'finalizado_en' => now(),
            ]);
        });
    }

    /** @return array{string, string, string, string} */
    private function content(JobExecution $execution): array
    {
        $title = $execution->entrada['titulo'] ?? null;
        $message = $execution->entrada['mensaje'] ?? null;
        if (is_string($title) && is_string($message)
            && is_string($execution->tipo_recurso) && is_string($execution->recurso_id)) {
            return [$title, $message, $execution->tipo_recurso, $execution->recurso_id];
        }

        $syllabus = $this->syllabusFor($execution);

        return [...$this->message($execution, $syllabus), 'silabo', $syllabus->id];
    }

    /** @return array{string, string} */
    private function message(JobExecution $execution, Syllabus $syllabus): array
    {
        $revision = $execution->entrada['numero_revision'] ?? null;
        $revisionLabel = is_int($revision) ? ", revisión {$revision}" : '';

        return match ($this->eventType($execution)) {
            'silabo.enviado', 'silabo.reenviado' => [
                'Sílabo listo para revisión',
                "El sílabo {$syllabus->subject->nombre}{$revisionLabel} fue enviado a coordinación.",
            ],
            'silabo.correccion_solicitada' => [
                'Corrección solicitada',
                "Coordinación solicitó corregir el sílabo {$syllabus->subject->nombre}{$revisionLabel}.",
            ],
            'silabo.aprobado' => [
                'Sílabo aprobado',
                "El sílabo {$syllabus->subject->nombre}{$revisionLabel} fue aprobado.",
            ],
            'silabo.reabierto' => [
                'Sílabo reabierto',
                "El sílabo {$syllabus->subject->nombre}{$revisionLabel} fue reabierto para corrección.",
            ],
            'silabo.firmado_docente' => [
                'Sílabo listo para su firma',
                "El docente firmó electrónicamente el sílabo {$syllabus->subject->nombre}{$revisionLabel}. Falta la firma de coordinación.",
            ],
            'silabo.firmado_coordinacion' => [
                'Sílabo listo para su firma',
                "Docente y coordinación firmaron el sílabo {$syllabus->subject->nombre}{$revisionLabel}. Falta la firma de biblioteca.",
            ],
            'biblioteca.verificado' => [
                'Bibliografía aprobada',
                "Biblioteca aprobó la bibliografía del sílabo {$syllabus->subject->nombre}{$revisionLabel}. Genere el PDF y fírmelo.",
            ],
            'silabo.firmado' => [
                'Sílabo firmado',
                "Biblioteca firmó electrónicamente el sílabo {$syllabus->subject->nombre}{$revisionLabel}. El PDF con las tres firmas ya está disponible.",
            ],
            'biblioteca.enviado' => [
                'Bibliografía por verificar',
                "El docente envió el sílabo {$syllabus->subject->nombre}{$revisionLabel} para verificar su bibliografía.",
            ],
            'biblioteca.comentado' => [
                'Biblioteca pidió corregir la bibliografía',
                "Biblioteca comentó la bibliografía del sílabo {$syllabus->subject->nombre}{$revisionLabel}. Corríjala y reenvíela.",
            ],
            'silabo.bibliografia_corregida' => [
                'Bibliografía corregida',
                "El docente corrigió la bibliografía del sílabo {$syllabus->subject->nombre}{$revisionLabel} por pedido de biblioteca. Vuelve a biblioteca para su verificación.",
            ],
            'silabo.reiniciado' => [
                'Sílabo reiniciado',
                "Coordinación reinició el sílabo {$syllabus->subject->nombre}. Empiece de nuevo con la malla y la plantilla actuales.",
            ],
            default => throw new RuntimeException('Tipo de notificación interna no soportado.'),
        };
    }

    /** @return list<string> */
    private function recipientIds(JobExecution $execution): array
    {
        $values = $execution->entrada['destinatarios_ids'] ?? null;
        if (! is_array($values)) {
            throw new RuntimeException('El trabajo no contiene destinatarios válidos.');
        }

        return array_values(array_unique(array_filter($values, is_string(...))));
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    private function stringList(iterable $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return array_values(array_unique($strings));
    }

    private function eventType(JobExecution $execution): string
    {
        $type = $execution->entrada['tipo_evento'] ?? null;
        if (! is_string($type) || $type === '') {
            throw new RuntimeException('El trabajo no contiene un tipo de evento válido.');
        }

        return $type;
    }

    private function syllabusFor(JobExecution $execution): Syllabus
    {
        return Syllabus::query()->with('parallel.scheduledSubject.subject:id,nombre')->findOrFail($execution->recurso_id);
    }

    private function notificationKey(JobExecution $execution): string
    {
        $legacyEventId = $execution->entrada['evento_saliente_id'] ?? null;

        return 'flujo:'.(is_string($legacyEventId) && $legacyEventId !== '' ? $legacyEventId : $execution->id);
    }

    private function lockedExecution(): ?JobExecution
    {
        $direct = JobExecution::query()
            ->where('tipo', 'notificacion.interna')
            ->lockForUpdate()
            ->find($this->executionId);
        if ($direct !== null) {
            return $direct;
        }

        return JobExecution::query()
            ->where('tipo', 'notificacion.interna')
            ->where('entrada->evento_saliente_id', $this->executionId)
            ->lockForUpdate()
            ->first();
    }
}
