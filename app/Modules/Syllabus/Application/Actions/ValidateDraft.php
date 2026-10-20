<?php

namespace App\Modules\Syllabus\Application\Actions;

use App\Models\User;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Configuration\Domain\DocumentBlock;
use App\Modules\Configuration\Domain\FieldDocument;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Configuration\Infrastructure\Persistence\Models\FieldDefinition;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\DraftValidation;
use App\Modules\Syllabus\Application\IdentificationCard;
use App\Modules\Syllabus\Application\SyllabusDocumentFields;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ValidateDraft
{
    public const RULE_VERSION = 'field-document-v9';

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function execute(Syllabus $syllabus, User $actor, Request $request): DraftValidation
    {
        return DB::transaction(function () use ($actor, $request, $syllabus): DraftValidation {
            $locked = Syllabus::query()->lockForUpdate()->findOrFail($syllabus->id);
            $fields = SyllabusDocumentFields::fields($locked);
            $values = $locked->values()->get()->keyBy('definicion_campo_id');
            $issues = [];
            $variables = TemplateVariables::resolve(['identification' => IdentificationCard::fromSyllabus($locked)]);
            foreach ($fields as $field) {
                if ($field->quien_llena === FieldDefinition::INSTITUTIONAL) {
                    continue;
                }
                if (FieldDocument::isEmpty($values->get($field->id)?->valor)) {
                    $fromCareer = ! FieldValue::editableByTeacher($field, $values->get($field->id));
                    $issues[] = [
                        'field_id' => $field->id,
                        'code' => $fromCareer ? 'maestro_obligatorio_faltante' : 'campo_obligatorio_faltante',
                        'severity' => 'error',
                        'message' => $fromCareer
                            ? 'La asignatura no tiene ficha: pídala al coordinador.'
                            : "«{$field->displayName()}» está vacío. Complétalo antes de enviar.",
                    ];
                }
                $issues = [...$issues, ...$this->blockIssues($field, $values->get($field->id)?->valor, $variables)];
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
