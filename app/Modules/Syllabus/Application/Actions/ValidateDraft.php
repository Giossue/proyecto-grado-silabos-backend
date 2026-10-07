<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\FieldConditions;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\TemplateDocument;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\DraftValidation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ValidateDraft
{
    public const RULE_VERSION = 'free-text-v6';

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(Syllabus $syllabus, User $actor, Request $request): DraftValidation
    {
        return DB::transaction(function () use ($actor, $request, $syllabus): DraftValidation {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $fields = FieldDefinition::query()
                ->with('template')
                ->get();
            $values = $locked->values()->get()->keyBy('definicion_campo_id');
            $issues = [];
            $hidden = array_flip(FieldConditions::hiddenKeys(
                $fields,
                $fields->mapWithKeys(fn (FieldDefinition $field): array => [$field->clave => $values->get($field->id)?->valor])->all(),
            ));

            foreach ($fields as $field) {
                if (isset($hidden[$field->clave]) || ! $this->requiredFromTeacher($field)) {
                    continue;
                }
                if (! $this->hasValue($field, $values->get($field->id)?->valor)) {
                    $issues[] = [
                        'field_id' => $field->id,
                        'code' => $field->heredado ? 'maestro_obligatorio_faltante' : 'campo_obligatorio_faltante',
                        'severity' => 'error',
                        'message' => $field->heredado
                            ? ($field->fromProfile()
                                ? 'La asignatura no tiene ficha: pídala al coordinador.'
                                : ($field->origen_maestro === FieldDefinition::ORIGIN_TEACHER
                                    ? 'Complete su formación y experiencia en su perfil.'
                                    : "Avise a Coordinación: falta el dato «{$field->displayName()}» en la información de la carrera."))
                            : "«{$field->displayName()}» está vacío. Complétalo antes de enviar.",
                    ];
                }
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
            );

            return new DraftValidation($locked->version_bloqueo, $issues);
        });
    }

    private function requiredFromTeacher(FieldDefinition $field): bool
    {
        // El dato automático se resuelve solo al enviar; el docente no lo llena.
        if (! $field->hasLegacyClass() && ! $field->fieldType()->teacherEditable() && $field->fieldType()->key() === 'automatico') {
            return false;
        }
        if (($field->typeConfig()['optional'] ?? false) === true) {
            return false;
        }
        $block = $field->block;
        if ($block === null || $block->contentType() === 'fixed') {
            return false;
        }
        $configuration = $block->configuracion ?? [];
        $detached = $configuration['detached_fields'] ?? [];
        if (is_array($detached) && array_key_exists($field->clave, $detached)) {
            return false;
        }
        if ($field->heredado) {
            // Un dato de la ficha que el docente puede ajustar llega como sugerencia: no es obligatorio.
            return ! ($field->fromProfile() && $field->editable_docente);
        }
        if (! $field->editable_docente) {
            return false;
        }
        if (! $field->hasLegacyClass()) {
            return true;
        }
        $document = $configuration['document'] ?? null;
        if (! is_array($document)) {
            return true;
        }
        $keys = array_map(static fn (array $node): mixed => $node['attrs']['key'] ?? null, TemplateDocument::nodes($document, 'field'));

        return in_array($field->clave, $keys, true);
    }

    /** Los campos del modelo nuevo deciden por su tipo si están vacíos; los de la clase anterior, por su valor. */
    private function hasValue(FieldDefinition $field, mixed $value): bool
    {
        // La formación del docente se hereda de su perfil como texto plano, no como documento.
        return $field->hasLegacyClass() || $field->origen_maestro === FieldDefinition::ORIGIN_TEACHER
            ? self::filled($value)
            : ! $field->fieldType()->isEmpty($value, $field->typeConfig());
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
