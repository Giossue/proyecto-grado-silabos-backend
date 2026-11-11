<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Domain\ResponseKind;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\DraftValidation;
use App\Modules\Syllabus\Application\IdentificationCard;
use App\Modules\Syllabus\Application\SyllabusDocumentFields;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidateDraft
{
    public const RULE_VERSION = 'field-document-v11';

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
        private readonly SubjectProfileFields $profiles,
    ) {}

    public function execute(Syllabus $syllabus, User $actor, Request $request): DraftValidation
    {
        return DB::transaction(function () use ($actor, $request, $syllabus): DraftValidation {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $fields = SyllabusDocumentFields::fields($locked);
            $values = $locked->values()->get()->keyBy('definicion_campo_id');
            $issues = [];
            $variables = TemplateVariables::resolve(['identification' => IdentificationCard::fromSyllabus($locked)]);
            $templates = $this->profiles->templates((string) $locked->parallel()->value('asignatura_id'));
            foreach ($fields as $field) {
                if (! SubjectProfileFields::teacherEditable($field, $templates)) {
                    continue;
                }
                $template = $templates[$field->id] ?? $field->contenido;
                $empty = FieldResponse::isEmpty($template, $values->get($field->id)?->valor);
                $pending = ResponseKind::pending(FreeText::isDocument($values->get($field->id)?->valor) ? $values->get($field->id)?->valor : $template);
                if ($empty) {
                    $issues[] = [
                        'field_id' => $field->id,
                        'code' => 'campo_obligatorio_faltante',
                        'severity' => 'error',
                        'message' => "«{$field->displayName()}» está vacío. Complétalo antes de enviar.",
                    ];
                }
                if ($pending['without_options'] > 0) {
                    $issues[] = ['field_id' => $field->id, 'code' => 'respuesta_sin_opciones', 'severity' => 'error', 'message' => "«{$field->displayName()}» tiene una respuesta sin opciones para elegir: pídalas al coordinador."];
                }
                if (! $empty && $pending['unanswered'] > 0) {
                    $issues[] = ['field_id' => $field->id, 'code' => 'respuesta_faltante', 'severity' => 'error', 'message' => "«{$field->displayName()}» tiene una respuesta sin contestar. Elija una opción o escriba la respuesta antes de enviar."];
                }
                $stored = $values->get($field->id)?->valor;
                if (FieldResponse::enabled() && is_array($stored)) {
                    try {
                        FieldResponse::normalize($template, $stored);
                    } catch (ValidationException) {
                        $issues[] = ['field_id' => $field->id, 'code' => 'estructura_respuesta_modificada', 'severity' => 'error', 'message' => 'El formato de este campo cambió. Complete las respuestas en la estructura vigente.'];
                    }
                }
                $issues = [...$issues, ...$this->blockIssues($field, $values->get($field->id)?->valor, $variables)];
            }
            $issues = [...$issues, ...$this->formationIssues($locked, $actor)];

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

    public static function formationRequired(): bool
    {
        return array_filter(
            TemplateVariables::definitions(),
            fn (array $definition): bool => $definition['source'] === 'identification.'.IdentificationCard::INPUT_KEYS['formacion_experiencia'],
        ) !== [];
    }

    /** @return list<array{field_id: null, code: string, severity: string, message: string}> */
    private function formationIssues(Syllabus $syllabus, User $actor): array
    {
        if (! self::formationRequired()) {
            return [];
        }
        $issues = [];
        foreach ($syllabus->teachers()->get() as $teacher) {
            if (trim((string) $teacher->formacion_experiencia) !== '') {
                continue;
            }
            $issues[] = [
                'field_id' => null,
                'code' => 'formacion_docente_faltante',
                'severity' => 'error',
                'message' => $teacher->is($actor)
                    ? 'Falta su formación y experiencia académica-investigativa. Complétela antes de enviar.'
                    : "Falta la formación y experiencia académica-investigativa de {$teacher->nombre}.",
            ];
        }

        return $issues;
    }

    /**
     * @param  array<string, string>  $variables
     * @return list<array{field_id: string, code: string, severity: string, message: string}>
     */
    private function blockIssues(FieldDefinition $field, mixed $value, array $variables): array
    {
        if (! FreeText::isDocument($value)) {
            return [];
        }
        /** @var array<string, mixed> $value */
        $issues = [];
        foreach (is_array($value['content'] ?? null) ? $value['content'] : [] as $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'documentBlock') {
                continue;
            }
            foreach (DocumentBlock::failures($node, $variables) as $failure) {
                $issues[] = ['field_id' => $field->id, 'code' => $failure['code'], 'severity' => 'error', 'message' => $failure['message']];
            }
        }

        return $issues;
    }

    public static function filled(mixed $value): bool
    {
        if (FreeText::isDocument($value)) {
            /**
             * @var array<string, mixed> $value
             */
            return ! FreeText::isEmpty($value);
        }

        return $value !== null && (! is_string($value) || trim($value) !== '') && (! is_array($value) || $value !== []);
    }
}
