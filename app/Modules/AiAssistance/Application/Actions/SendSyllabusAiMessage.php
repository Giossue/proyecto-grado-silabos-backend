<?php

namespace App\Modules\AiAssistance\Application\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Application\BuildSyllabusChatContext;
use App\Modules\AiAssistance\Infrastructure\Jobs\AnswerSyllabusChatJob;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El chat vive en la sesión `silabo:{id}:usuario:{usuario}`: la pregunta y la respuesta
 * pendiente (con su evidencia fijada) son dos mensajes consecutivos.
 */
class SendSyllabusAiMessage
{
    public function __construct(
        private readonly BuildSyllabusChatContext $context,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(Syllabus $syllabus, User $actor, string $content, string $idempotencyKey, Request $request): AiHistoryEntry
    {
        return DB::transaction(function () use ($actor, $content, $idempotencyKey, $request, $syllabus): AiHistoryEntry {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $session = AiHistoryEntry::chatSession($locked->id, $actor->id);
            $existing = AiHistoryEntry::query()
                ->session($session)
                ->ofType('mensaje')
                ->where('mensaje_ia->rol', 'usuario')
                ->where('mensaje_ia->entrada->clave_idempotencia', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return AiHistoryEntry::query()
                    ->session($session)
                    ->where('mensaje_ia->entrada->respuesta_a_id', $existing->id)
                    ->firstOrFail();
            }
            $question = AiHistoryEntry::query()->create([
                'sesion_id' => $session,
                'mensaje_ia' => [
                    'tipo' => 'mensaje',
                    'rol' => 'usuario',
                    'estado' => 'completado',
                    'entrada' => [
                        'contenido' => trim($content),
                        'clave_idempotencia' => $idempotencyKey,
                        'version_bloqueo_origen' => $locked->version_bloqueo,
                        'usuario_id' => $actor->id,
                        'context_version' => BuildSyllabusChatContext::VERSION,
                    ],
                ],
            ]);
            $evidence = array_map(fn (array $item): array => [
                'id' => $item['id'],
                'tipo' => $item['type'],
                'definicion_campo_id' => $item['field_id'],
                'fuente_academica_id' => $item['source_id'],
                'documento_id' => $item['fragment_id'],
                'etiqueta' => $item['label'],
                'extracto' => $item['excerpt'],
                'huella' => $item['fingerprint'],
            ], $this->context->build($locked));

            $answerId = (string) Str::uuid();
            $correlationId = $request->attributes->getString('correlation_id');
            $job = JobExecution::query()->create([
                'tipo' => 'ia.chat',
                'cola' => 'ia',
                'estado' => 'pendiente',
                'clave_idempotencia' => "ia.chat:{$answerId}",
                'correlacion_id' => Str::isUuid($correlationId) ? $correlationId : (string) Str::uuid(),
                'tipo_recurso' => 'mensaje_ia',
                'recurso_id' => $answerId,
                'intentos' => 0,
                'intentos_maximos' => 3,
                'progreso' => 0,
            ]);
            $answer = AiHistoryEntry::query()->create([
                'id' => $answerId,
                'sesion_id' => $session,
                'mensaje_ia' => [
                    'tipo' => 'mensaje',
                    'rol' => 'asistente',
                    'estado' => 'pendiente',
                    'entrada' => [
                        'respuesta_a_id' => $question->id,
                        'version_bloqueo_origen' => $locked->version_bloqueo,
                        'ejecucion_trabajo_id' => $job->id,
                        'evidencia' => $evidence,
                        'context_version' => BuildSyllabusChatContext::VERSION,
                    ],
                    'contenido' => '',
                    'citas' => [],
                ],
            ]);
            AnswerSyllabusChatJob::dispatch($answer->id)->afterCommit();
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: null,
                action: 'ia.chat_solicitado',
                resourceType: 'mensaje_ia',
                resourceId: $answer->id,
                result: 'exito',
                metadata: ['syllabus_id' => $locked->id, 'draft_version' => $locked->version_bloqueo],
                correlationId: $job->correlacion_id,
            );

            return $answer->refresh();
        });
    }
}
