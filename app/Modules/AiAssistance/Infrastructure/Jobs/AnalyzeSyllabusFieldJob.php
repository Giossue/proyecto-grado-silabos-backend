<?php

namespace App\Modules\AiAssistance\Infrastructure\Jobs;

use App\Modules\AiAssistance\Application\AiResultContract;
use App\Modules\AiAssistance\Application\RefreshSyllabusAiReview;
use App\Modules\AiAssistance\Domain\Contracts\AiAnalysisGateway;
use App\Modules\AiAssistance\Domain\Data\AiAnalysisInput;
use App\Modules\AiAssistance\Domain\Data\AiEvidenceInput;
use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Domain\Exceptions\AiGatewayUnavailable;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiHistoryEntry;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeSyllabusFieldJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /**
     * El contrato del gateway habla inglés (PV-13/14); la BD almacena español.
     * La traducción ocurre únicamente en el punto de persistencia.
     *
     * @var array<string, string>
     */
    private const MOTIVOS_NO_CONCLUYENTE = [
        'evidence_limit_exceeded' => 'limite_evidencia_excedido',
        'insufficient_evidence' => 'evidencia_insuficiente',
        'empty_content' => 'contenido_vacio',
        'no_editorial_change' => 'sin_cambio_editorial',
    ];

    /** @var array<string, string> */
    private const TIPOS_RECOMENDACION = [
        'clarity' => 'claridad',
        'consistency' => 'consistencia',
    ];

    /** @param string $executionId Identificador del mensaje `analisis` en `historial_ia`. */
    public function __construct(public readonly string $executionId)
    {
        $this->onQueue('ia');
    }

    public function handle(
        AiAnalysisGateway $gateway,
        AiResultContract $contract,
        RecordAuditEvent $audit,
        ?RefreshSyllabusAiReview $refreshReview = null,
    ): void {
        $refreshReview ??= app(RefreshSyllabusAiReview::class);
        if (! $this->markRunning()) {
            return;
        }
        $refreshReview->forExecution($this->executionId);

        $analysis = AiHistoryEntry::query()->findOrFail($this->executionId);
        if ($analysis->evidence() === []) {
            $this->finishInconclusive('insufficient_evidence', $gateway->version(), $audit, $refreshReview);

            return;
        }

        $input = $this->input($analysis);
        try {
            $result = $gateway->analyze($input);
            $contract->validate($input, $result);
        } catch (AiContractException) {
            $this->finishFailed(
                'contrato_ia_invalido',
                'El servicio devolvió una respuesta que no pudo verificarse. El borrador no fue modificado.',
                $audit,
                $refreshReview,
            );

            return;
        }

        if ($result->status === 'inconclusive') {
            $this->finishInconclusive(
                $result->inconclusiveReason ?? 'insufficient_evidence',
                $result->gatewayVersion,
                $audit,
                $refreshReview,
            );

            return;
        }

        DB::transaction(function () use ($audit, $result): void {
            $analysis = AiHistoryEntry::query()->lockForUpdate()->findOrFail($this->executionId);
            if (! $analysis->isOpen()) {
                return;
            }
            $evidenceIds = array_column($analysis->evidence(), 'id');
            $recommendations = [];
            foreach ($result->recommendations as $index => $output) {
                $recommendations[] = [
                    'id' => (string) Str::uuid(),
                    'ordinal' => $index + 1,
                    // El contrato emite `clarity`/`consistency`; la BD guarda español.
                    'tipo' => self::TIPOS_RECOMENDACION[$output->type] ?? $output->type,
                    'titulo' => $output->title,
                    'explicacion' => $output->explanation,
                    'texto_sugerido' => $output->suggestedText,
                    'evidencia_ids' => array_values(array_intersect(array_unique($output->evidenceIds), $evidenceIds)),
                ];
            }
            $analysis->amend([
                'estado' => 'completada',
                'version_pasarela_ejecutada' => $result->gatewayVersion,
                'recomendaciones' => $recommendations,
                'completado_en' => now()->toIso8601String(),
            ]);
            $job = JobExecution::query()->lockForUpdate()->findOrFail($analysis->inputString('ejecucion_trabajo_id'));
            $job->update([
                'estado' => 'completada',
                'progreso' => 100,
                'resultado' => [
                    'ai_execution_id' => $analysis->id,
                    'status' => 'completed',
                    'recommendation_count' => count($recommendations),
                ],
                'finalizado_en' => now(),
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);
            $this->notify($analysis, 'completada');
            $audit->execute(
                actorId: null,
                roleAssignmentId: null,
                action: 'ia.analisis_completado',
                resourceType: 'ejecucion_ia',
                resourceId: $analysis->id,
                result: 'exito',
                metadata: [
                    'gateway_version' => $result->gatewayVersion,
                    'recommendation_count' => count($recommendations),
                ],
                correlationId: $job->correlacion_id,
            );
        });
        $refreshReview->forExecution($this->executionId);
    }

    public function failed(Throwable $exception): void
    {
        $code = $exception instanceof AiGatewayUnavailable
            ? 'servicio_ia_no_disponible'
            : 'analisis_ia_fallido';
        $message = $exception instanceof AiGatewayUnavailable
            ? 'La ayuda de IA no está disponible por el momento. Puede continuar editando y volver a solicitarla.'
            : 'No fue posible completar la ayuda de IA. El borrador no fue modificado.';
        $this->finishFailed(
            $code,
            $message,
            app(RecordAuditEvent::class),
            app(RefreshSyllabusAiReview::class),
        );
    }

    private function markRunning(): bool
    {
        return DB::transaction(function (): bool {
            $analysis = AiHistoryEntry::query()->lockForUpdate()->find($this->executionId);
            if ($analysis === null || ! $analysis->isOpen()) {
                return false;
            }
            $job = JobExecution::query()->lockForUpdate()->findOrFail($analysis->inputString('ejecucion_trabajo_id'));
            $analysis->amend([
                'estado' => 'en_ejecucion',
                'iniciado_en' => $analysis->part('iniciado_en') ?? now()->toIso8601String(),
            ]);
            $job->update([
                'estado' => 'en_ejecucion',
                'intentos' => $job->intentos + 1,
                'progreso' => 15,
                'iniciado_en' => $job->iniciado_en ?? now(),
                'finalizado_en' => null,
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);

            return true;
        });
    }

    private function finishInconclusive(
        string $reason,
        string $gatewayVersion,
        RecordAuditEvent $audit,
        RefreshSyllabusAiReview $refreshReview,
    ): void {
        DB::transaction(function () use ($audit, $gatewayVersion, $reason): void {
            $analysis = AiHistoryEntry::query()->lockForUpdate()->findOrFail($this->executionId);
            if (! $analysis->isOpen()) {
                return;
            }
            $analysis->amend([
                'estado' => 'no_concluyente',
                'version_pasarela_ejecutada' => $gatewayVersion,
                // `$reason` llega en el vocabulario del contrato; se traduce al persistir.
                'motivo_no_concluyente' => self::MOTIVOS_NO_CONCLUYENTE[$reason] ?? $reason,
                'completado_en' => now()->toIso8601String(),
            ]);
            $job = JobExecution::query()->lockForUpdate()->findOrFail($analysis->inputString('ejecucion_trabajo_id'));
            $job->update([
                'estado' => 'completada',
                'progreso' => 100,
                'resultado' => ['ai_execution_id' => $analysis->id, 'status' => 'inconclusive', 'reason' => $reason],
                'finalizado_en' => now(),
                'codigo_error' => null,
                'mensaje_error' => null,
            ]);
            $this->notify($analysis, 'no_concluyente');
            $audit->execute(
                actorId: null,
                roleAssignmentId: null,
                action: 'ia.analisis_no_concluyente',
                resourceType: 'ejecucion_ia',
                resourceId: $analysis->id,
                result: 'exito',
                metadata: ['reason' => $reason, 'gateway_version' => $gatewayVersion],
                correlationId: $job->correlacion_id,
            );
        });
        $refreshReview->forExecution($this->executionId);
    }

    private function finishFailed(
        string $code,
        string $message,
        RecordAuditEvent $audit,
        RefreshSyllabusAiReview $refreshReview,
    ): void {
        DB::transaction(function () use ($audit, $code, $message): void {
            $analysis = AiHistoryEntry::query()->lockForUpdate()->find($this->executionId);
            if ($analysis === null || ! $analysis->isOpen()) {
                return;
            }
            $analysis->amend([
                'estado' => 'fallida',
                'codigo_error' => $code,
                'mensaje_error' => $message,
                'completado_en' => now()->toIso8601String(),
            ]);
            $job = JobExecution::query()->lockForUpdate()->find($analysis->inputString('ejecucion_trabajo_id'));
            if ($job !== null) {
                $job->update([
                    'estado' => 'fallida',
                    'progreso' => 0,
                    'resultado' => null,
                    'codigo_error' => $code,
                    'mensaje_error' => $message,
                    'finalizado_en' => now(),
                ]);
                $audit->execute(
                    actorId: null,
                    roleAssignmentId: null,
                    action: 'ia.analisis_fallido',
                    resourceType: 'ejecucion_ia',
                    resourceId: $analysis->id,
                    result: 'fallido',
                    metadata: ['error_code' => $code],
                    correlationId: $job->correlacion_id,
                );
            }
        });
        $refreshReview->forExecution($this->executionId);
    }

    private function input(AiHistoryEntry $analysis): AiAnalysisInput
    {
        $evidence = [];
        foreach ($analysis->evidence() as $item) {
            $evidence[] = new AiEvidenceInput(
                (string) $item['id'],
                $item['tipo'] === 'seccion_silabo'
                    ? (string) $item['definicion_campo_id']
                    : (string) $item['fuente_academica_id'],
                (string) $item['etiqueta'],
                (string) $item['extracto'],
                (string) $item['huella'],
                (string) $item['tipo'],
            );
        }
        $label = FieldDefinition::query()->whereKey($analysis->input('definicion_campo_id'))->value('etiqueta');

        return new AiAnalysisInput(
            $analysis->id,
            (string) $analysis->input('version_contrato'),
            (string) $analysis->input('version_instruccion'),
            (string) $analysis->input('clave_campo'),
            is_string($label) ? $label : (string) $analysis->input('clave_campo'),
            (string) $analysis->input('contenido'),
            (string) $analysis->input('huella_contenido'),
            (string) $analysis->input('idioma'),
            $evidence,
            (int) config('ai.limits.recommendations'),
        );
    }

    private function notify(AiHistoryEntry $analysis, string $estado): void
    {
        if ($analysis->input('revision_id') !== null) {
            return;
        }
        InternalNotification::query()->firstOrCreate(
            [
                'usuario_id' => $analysis->input('solicitado_por'),
                'clave_deduplicacion' => "ia.analisis.{$estado}:{$analysis->id}",
            ],
            [
                'tipo' => "ia.analisis.{$estado}",
                'titulo' => $estado === 'completada' ? 'Recomendación de IA disponible' : 'Análisis de IA no concluyente',
                'mensaje' => $estado === 'completada'
                    ? 'La ayuda solicitada está lista para revisión. Ningún cambio se aplicó automáticamente.'
                    : 'La ayuda no produjo recomendaciones verificables. Puede continuar trabajando normalmente.',
                'tipo_recurso' => 'ejecucion_ia',
                'recurso_id' => $analysis->id,
                'notificado_en' => now(),
            ],
        );
    }
}
