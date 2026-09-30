<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\CurricularFieldOptions;
use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\RequiredAcademicTemplateData;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\ValidationResult;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\ValidationRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ValidateDraft
{
    public const RULE_VERSION = 'free-text-v6';

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly RequiredAcademicTemplateData $academicTemplateData,
    ) {}

    public function execute(Syllabus $syllabus, User $actor, Request $request): ValidationRun
    {
        return DB::transaction(function () use ($actor, $request, $syllabus): ValidationRun {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $fields = FieldDefinition::query()
                ->with('template')
                ->get();
            $values = $locked->values()->get()->keyBy('definicion_campo_id');
            $academicContext = $locked->contexto_academico ?? [];
            $issues = $this->academicTemplateData->issues($academicContext);
            $hidden = array_flip(FieldConditions::hiddenKeys(
                $fields,
                $fields->mapWithKeys(fn (FieldDefinition $field): array => [$field->clave => $values->get($field->id)?->valor])->all(),
            ));

            foreach ($fields as $field) {
                if (isset($hidden[$field->clave]) || ! $this->requiredFromTeacher($field)) {
                    continue;
                }
                $optionsSource = CurricularFieldOptions::sources($field);
                if ($optionsSource !== []) {
                    $allowed = CurricularFieldOptions::values($field, $academicContext);
                    $value = $values->get($field->id)?->valor;
                    $selection = is_array($value) ? $value : ($value === null ? [] : [$value]);
                    if ($allowed === []) {
                        $issues[] = [
                            'field_id' => $field->id,
                            'code' => 'catalogo_curricular_faltante',
                            'severity' => 'error',
                            'message' => "Avise a Coordinación: falta el catálogo curricular de «{$field->displayName()}» para esta asignatura.",
                        ];

                        continue;
                    }
                    if (($value !== null && $field->tipo === 'seleccion_multiple' && (! is_array($value) || ! array_is_list($value)))
                        || ($value !== null && $field->tipo === 'seleccion_unica' && ! is_string($value))
                        || array_filter($selection, static fn (mixed $item): bool => ! is_string($item) || ! in_array($item, $allowed, true)) !== []) {
                        $issues[] = [
                            'field_id' => $field->id,
                            'code' => 'seleccion_curricular_invalida',
                            'severity' => 'error',
                            'message' => "«{$field->displayName()}» contiene una opción que no corresponde al catálogo curricular de esta asignatura.",
                        ];
                    }
                }
                if (! self::filled($values->get($field->id)?->valor)) {
                    $issues[] = [
                        'field_id' => $field->id,
                        'code' => $field->heredado ? 'maestro_obligatorio_faltante' : 'campo_obligatorio_faltante',
                        'severity' => 'error',
                        'message' => $field->heredado
                            ? "Avise a Coordinación: falta el dato «{$field->displayName()}» en la información de la carrera."
                            : "«{$field->displayName()}» está vacío. Complétalo antes de enviar.",
                    ];
                }
            }

            $run = ValidationRun::query()->create([
                'silabo_id' => $locked->id,
                'ejecutado_por' => $actor->id,
                'version_reglas' => self::RULE_VERSION,
                'estado' => 'completada',
                'version_bloqueo' => $locked->version_bloqueo,
                'errores_bloqueantes' => count($issues),
                'advertencias' => 0,
                'completado_en' => now(),
            ]);
            foreach ($issues as $issue) {
                ValidationResult::query()->create([
                    'ejecucion_validacion_id' => $run->id,
                    'definicion_campo_id' => $issue['field_id'],
                    'codigo' => $issue['code'],
                    'severidad' => $issue['severity'],
                    'mensaje' => $issue['message'],
                ]);
            }
            $activeRole = $this->roles->resolve($request);
            $this->audit->execute(
                actorId: $actor->id,
                roleAssignmentId: $activeRole?->id,
                action: 'silabo.validado',
                resourceType: 'silabo',
                resourceId: $locked->id,
                result: 'exito',
                metadata: ['rule_version' => self::RULE_VERSION, 'blocking_errors' => count($issues)],
                correlationId: $request->attributes->getString('correlation_id') ?: null,
            );

            return $run->load('results');
        });
    }

    private function requiredFromTeacher(FieldDefinition $field): bool
    {
        $block = $field->block;
        if ($block === null || $block->contentType() === 'fixed') {
            return false;
        }
        if ($field->heredado) {
            return true;
        }
        if (! $field->editable_docente) {
            return false;
        }
        $configuration = $block->configuracion ?? [];
        $detached = $configuration['detached_fields'] ?? [];
        if (is_array($detached) && array_key_exists($field->clave, $detached)) {
            return false;
        }
        $document = $configuration['document'] ?? null;
        if (! is_array($document)) {
            return true;
        }
        $keys = array_map(static fn (array $node): mixed => $node['attrs']['key'] ?? null, TemplateDocument::nodes($document, 'field'));

        return in_array($field->clave, $keys, true);
    }

    public static function filled(mixed $value): bool
    {
        if (FreeText::isDocument($value)) {
            /** @var array<string, mixed> $value */
            return ! FreeText::isEmpty($value);
        }

        return $value !== null && (! is_string($value) || trim($value) !== '') && (! is_array($value) || $value !== []);
    }
}
