<?php

namespace App\Modules\AiAssistance\Application\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Application\BuildSyllabusChatContext;
use App\Modules\AiAssistance\Infrastructure\Jobs\AnswerSyllabusChatJob;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiConversation;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiMessage;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiMessageEvidence;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendSyllabusAiMessage
{
    public function __construct(
        private readonly BuildSyllabusChatContext $context,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(Syllabus $syllabus, User $actor, string $content, string $idempotencyKey, Request $request): AiMessage
    {
        return DB::transaction(function () use ($actor, $content, $idempotencyKey, $request, $syllabus): AiMessage {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $conversation = AiConversation::query()->firstOrCreate(
                ['silabo_id' => $locked->id, 'usuario_id' => $actor->id],
                ['ocultada_en' => null, 'iniciada_en' => now()],
            );
            $existing = AiMessage::query()
                ->where('conversacion_ia_id', $conversation->id)
                ->where('clave_idempotencia', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
            $conversation->update(['ocultada_en' => null]);
            $question = AiMessage::query()->create([
                'conversacion_ia_id' => $conversation->id,
                'clave_idempotencia' => $idempotencyKey,
                'rol_mensaje_ia' => 'usuario',
                'estado_mensaje_ia' => 'completado',
                'contenido_mensaje_ia' => trim($content),
                'version_bloqueo_origen' => $locked->version_bloqueo,
                'completado_en' => now(),
                'enviado_en' => now(),
            ]);
            $answer = AiMessage::query()->create([
                'conversacion_ia_id' => $conversation->id,
                'respuesta_a_id' => $question->id,
                'rol_mensaje_ia' => 'asistente',
                'estado_mensaje_ia' => 'pendiente',
                'contenido_mensaje_ia' => '',
                'version_bloqueo_origen' => $locked->version_bloqueo,
                'enviado_en' => now(),
            ]);
            foreach ($this->context->build($locked, $content) as $evidence) {
                AiMessageEvidence::query()->create([
                    'id' => $evidence['id'],
                    'mensaje_ia_id' => $answer->id,
                    'tipo_evidencia_mensaje_ia' => $evidence['type'],
                    'definicion_campo_id' => $evidence['field_id'],
                    'fuente_academica_id' => $evidence['source_id'],
                    'etiqueta_evidencia_mensaje_ia' => $evidence['label'],
                    'extracto_evidencia_mensaje_ia' => $evidence['excerpt'],
                    'huella_evidencia_mensaje_ia' => $evidence['fingerprint'],
                    'fijada_en' => now(),
                ]);
            }
            $correlationId = $request->attributes->getString('correlation_id');
            $job = JobExecution::query()->create([
                'tipo' => 'ia.chat',
                'cola' => 'ia',
                'estado' => 'pendiente',
                'clave_idempotencia' => "ia.chat:{$answer->id}",
                'correlacion_id' => Str::isUuid($correlationId) ? $correlationId : (string) Str::uuid(),
                'tipo_recurso' => 'mensaje_ia',
                'recurso_id' => $answer->id,
                'intentos' => 0,
                'intentos_maximos' => 3,
                'progreso' => 0,
            ]);
            $answer->update(['ejecucion_trabajo_id' => $job->id]);
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
