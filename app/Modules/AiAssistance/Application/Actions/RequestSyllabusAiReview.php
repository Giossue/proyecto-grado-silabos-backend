<?php

namespace App\Modules\AiAssistance\Application\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\SyllabusAiReview;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\ValidationRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestSyllabusAiReview
{
    public function __construct(
        private readonly RequestAiAnalysis $requestAnalysis,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(
        Syllabus $syllabus,
        int $expectedLockVersion,
        string $idempotencyKey,
        User $actor,
        Request $request,
    ): SyllabusAiReview {
        return DB::transaction(function () use (
            $actor,
            $expectedLockVersion,
            $idempotencyKey,
            $request,
            $syllabus,
        ): SyllabusAiReview {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);

            if ($locked->version_bloqueo !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'version_bloqueo' => 'El borrador cambió. Espere al autoguardado y vuelva a intentarlo',
                ]);
            }

            $existing = SyllabusAiReview::query()
                ->where('silabo_id', $locked->id)
                ->where('version_bloqueo_origen', $locked->version_bloqueo)
                ->first();
            if ($existing !== null) {
                return $existing->load('executions');
            }
            if (SyllabusAiReview::query()
                ->where('silabo_id', $locked->id)
                ->where('clave_idempotencia', $idempotencyKey)
                ->exists()) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'La clave de reintento ya fue utilizada para otra versión del borrador',
                ]);
            }

            $validation = ValidationRun::query()
                ->where('silabo_id', $locked->id)
                ->where('version_bloqueo', $locked->version_bloqueo)
                ->latest('completado_en')
                ->first();

            if ($validation === null
                || $validation->errores_bloqueantes > 0) {
                throw ValidationException::withMessages([
                    'syllabus' => 'Complete y valide el sílabo sin errores antes de solicitar la revisión de IA',
                ]);
            }

            $values = FieldValue::query()
                ->where('silabo_id', $locked->id)
                ->get()
                ->keyBy('definicion_campo_id');
            $fields = FieldDefinition::query()
                ->where('plantilla_id', $locked->plantilla_id)
                ->where('editable_docente', true)
                ->where('heredado', false)
                ->whereIn('tipo', ['texto_corto', 'texto_largo'])
                ->orderBy('posicion')
                ->get()
                ->filter(function (FieldDefinition $field) use ($locked, $values): bool {
                    $value = $values->get($field->id)?->valor;

                    return $field->ia_habilitada
                        && is_string($value) && trim($value) !== '';
                });

            if ($fields->isEmpty()) {
                throw ValidationException::withMessages([
                    'syllabus' => 'No hay campos con contenido habilitados para la revisión de IA',
                ]);
            }

            $activeRole = $this->roles->resolve($request);
            $review = SyllabusAiReview::query()->create([
                'silabo_id' => $locked->id,
                'version_bloqueo_origen' => $locked->version_bloqueo,
                'clave_idempotencia' => $idempotencyKey,
                'estado_revision_ia' => 'pendiente',
                'total_campos' => $fields->count(),
                'campos_finalizados' => 0,
                'campos_con_recomendaciones' => 0,
                'campos_fallidos' => 0,
                'solicitado_por' => $actor->id,
                'asignacion_rol_id' => $activeRole?->id,
                'solicitado_en' => now(),
            ]);

            foreach ($fields as $field) {
                $this->requestAnalysis->execute(
                    $locked,
                    $field,
                    $review->id,
                    $actor,
                    $request,
                    $review->id,
                );
            }

            $correlationId = $request->attributes->getString('correlation_id');
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'ia.revision_silabo_solicitada',
                resourceType: 'revision_ia_silabo',
                resourceId: $review->id,
                result: 'exito',
                metadata: [
                    'version_bloqueo' => $locked->version_bloqueo,
                    'field_count' => $fields->count(),
                ],
                correlationId: Str::isUuid($correlationId) ? $correlationId : null,
            );

            return $review->load('executions');
        });
    }
}
