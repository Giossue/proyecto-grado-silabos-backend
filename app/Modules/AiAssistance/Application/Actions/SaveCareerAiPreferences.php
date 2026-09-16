<?php

namespace App\Modules\AiAssistance\Application\Actions;

use App\Models\User;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\CareerAiPreference;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCareerAiPreferences
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /** @param list<array{field_id: string, enabled: bool}> $preferences */
    public function execute(string $careerId, array $preferences, User $actor): void
    {
        DB::transaction(function () use ($actor, $careerId, $preferences): void {
            $template = SyllabusTemplate::query()->lockForUpdate()->firstOrFail();
            $fields = FieldDefinition::query()
                ->where('plantilla_id', $template->id)
                ->whereIn('id', array_column($preferences, 'field_id'))
                ->get()->keyBy('id');
            foreach ($preferences as $preference) {
                $field = $fields->get($preference['field_id']);
                if ($field === null || ! $field->ia_coordinacion_configurable) {
                    throw ValidationException::withMessages([
                        'preferences' => 'Uno de los campos no permite que Coordinación cambie el uso de IA.',
                    ]);
                }
                CareerAiPreference::query()->updateOrCreate(
                    ['carrera_id' => $careerId, 'definicion_campo_id' => $field->id],
                    [
                        'ia_habilitada' => $preference['enabled'],
                        'revision_plantilla_origen' => $template->revision_configuracion,
                        'configurado_por' => $actor->id,
                        'configurado_en' => now(),
                    ],
                );
            }
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: null,
                action: 'ia.preferencias_carrera_actualizadas',
                resourceType: 'carrera',
                resourceId: $careerId,
                result: 'exito',
                metadata: ['field_count' => count($preferences), 'template_revision' => $template->revision_configuracion],
                correlationId: null,
            );
        });
    }

    public function reset(string $careerId, User $actor): void
    {
        DB::transaction(function () use ($actor, $careerId): void {
            $count = CareerAiPreference::query()->where('carrera_id', $careerId)->delete();
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: null,
                action: 'ia.preferencias_carrera_restablecidas',
                resourceType: 'carrera', resourceId: $careerId, result: 'exito',
                metadata: ['deleted_count' => $count], correlationId: null,
            );
        });
    }
}
