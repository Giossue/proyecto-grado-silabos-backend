<?php

namespace App\Modules\AiAssistance\Application\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Application\BuildSyllabusChatContext;
use App\Modules\AiAssistance\Application\SyllabusAiLimits;
use App\Modules\AiAssistance\Infrastructure\Jobs\AnswerSyllabusChatJob;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendSyllabusAiMessage
{
    public function __construct(
        private readonly BuildSyllabusChatContext $context,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(Syllabus $syllabus, User $actor, string $content, string $idempotencyKey, Request $request): AiHistoryEntry
    {
        return DB::transaction(function () use ($actor, $content, $idempotencyKey, $syllabus): AiHistoryEntry {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $session = AiHistoryEntry::chatSession($locked->id, $actor->id);
            $existing = AiHistoryEntry::query()
                ->session($session)
                ->ofType('mensaje')
                ->where('contenido_mensaje->rol', 'usuario')
                ->where('contenido_mensaje->entrada->clave_idempotencia', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return AiHistoryEntry::query()
                    ->session($session)
                    ->where('contenido_mensaje->entrada->respuesta_a_id', $existing->id)
                    ->firstOrFail();
            }
            $history = AiHistoryEntry::query()->session($session)->whereIn('contenido_mensaje->tipo', ['mensaje', 'ocultamiento'])->orderBy('enviado_en')->get();
            if (SyllabusAiLimits::usage($history)['messages_used'] >= SyllabusAiLimits::MAX_MESSAGES) {
                throw ValidationException::withMessages(['message' => 'Llegó al límite de '.SyllabusAiLimits::MAX_MESSAGES.' preguntas al asistente para este sílabo.']);
            }
            $question = AiHistoryEntry::query()->create([
                ...$session,
                'contenido_mensaje' => [
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
            $job = JobExecution::query()->create([
                'tipo' => 'ia.chat',
                'cola' => 'ia',
                'estado' => 'pendiente',
                'clave_idempotencia' => "ia.chat:{$answerId}",
                'tipo_recurso' => 'mensaje_ia',
                'recurso_id' => $answerId,
                'intentos' => 0,
            ]);
            $answer = AiHistoryEntry::query()->create([
                'id' => $answerId,
                ...$session,
                'contenido_mensaje' => [
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
            );

            return $answer->refresh();
        });
    }
}
