<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\FichaFieldValues;
use App\Modules\Academic\Application\FichaWorkbookContext;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Domain\FichaWorkbookPreview;
use App\Modules\Configuration\Domain\FieldResponse;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplyFichaImport
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly ProcessLocks $locks,
        private readonly SubjectProfileFields $profile,
        private readonly RecordAuditEvent $audit,
        private readonly InProgressWork $work,
        private readonly FichaFieldValues $values,
        private readonly FichaWorkbookContext $workbookContext,
        private readonly PreviewFichaWorkbook $preview,
    ) {}

    /**
     * @param  list<string>  $subjectIds
     * @param  array<string, list<string>>|null  $fieldsBySubject
     */
    public function execute(UploadedFile $file, string $fingerprint, string $fileHash, array $subjectIds, User $actor, Request $request, ?array $fieldsBySubject = null): int
    {
        $preview = $this->preview->execute($file, $actor, $request);
        if (! hash_equals($preview->definition->fingerprint(), $fingerprint) || ! hash_equals($preview->fileHash, $fileHash)) {
            throw ValidationException::withMessages(['file' => 'El Excel o la estructura cambió desde la vista previa. Vuelva a revisar el archivo antes de aplicar.']);
        }
        $this->assertSelection($preview, $subjectIds, $fieldsBySubject);
        $careerId = $preview->definition->careerId;
        $role = $this->roles->resolve($request);
        abort_unless($role !== null && $role->carrera_id === $careerId, 403);

        return $this->work->keepContent($request, $careerId, fn (): int => DB::transaction(function () use ($preview, $careerId, $subjectIds, $actor, $role, $fieldsBySubject): int {
            DB::table('carreras')->where('carrera_id', $careerId)->lockForUpdate()->first();
            $this->locks->assertCareerEditable($careerId);
            if ($preview->definition->fingerprint() !== $this->workbookContext->for($careerId)->fingerprint()) {
                throw ValidationException::withMessages(['file' => 'La plantilla o las materias cambiaron. Descargue un Excel nuevo y vuelva a preparar la vista previa.']);
            }
            $fields = $this->profile->fields()->keyBy('clave');
            $applied = 0;
            foreach ($preview->subjects as $entry) {
                if (! in_array($entry['subject_id'], $subjectIds, true) || $entry['state'] !== 'lista') {
                    continue;
                }
                $chosenFields = $fieldsBySubject[$entry['subject_id']] ?? null;
                $changed = false;
                foreach ($entry['values'] as $key => $value) {
                    if (is_array($chosenFields) && ! in_array($key, $chosenFields, true)) {
                        continue;
                    }
                    $field = $fields->get($key);
                    if ($field === null) {
                        continue;
                    }
                    $document = $this->values->document($this->values->clean($value, $this->values->schema($field)['columns']), $field->contenido);
                    $document = FieldResponse::normalize($field->contenido, $document);
                    if (FieldResponse::isEmpty($field->contenido, $document)) {
                        continue;
                    }
                    FieldValue::query()->updateOrCreate(
                        ['asignatura_id' => $entry['subject_id'], 'definicion_campo_id' => $field->id],
                        ['valor' => FreeText::normalize($document)],
                    );
                    $changed = true;
                }
                if (! $changed) {
                    throw ValidationException::withMessages(['subjects' => 'Elija al menos un campo con contenido en cada materia seleccionada.']);
                }
                $applied++;
            }
            if ($applied === 0) {
                throw ValidationException::withMessages(['subjects' => 'Elija al menos una materia con proyecto de carrera propuesto.']);
            }
            $this->audit->execute($actor->id, $role->id, 'academico.ficha_importacion_aplicada', 'carrera', $careerId, 'exito', ['subjects' => $applied]);

            return $applied;
        }), $subjectIds);
    }

    /**
     * @param  list<string>  $subjectIds
     * @param  array<string, list<string>>|null  $fieldsBySubject
     */
    private function assertSelection(FichaWorkbookPreview $preview, array $subjectIds, ?array $fieldsBySubject): void
    {
        $entries = array_column($preview->subjects, null, 'subject_id');
        if ($fieldsBySubject !== null && array_diff(array_keys($fieldsBySubject), $subjectIds) !== []) {
            throw ValidationException::withMessages(['fields' => 'Los campos deben pertenecer a las materias seleccionadas.']);
        }
        foreach ($subjectIds as $subjectId) {
            $entry = $entries[$subjectId] ?? null;
            if ($entry === null || $entry['state'] !== 'lista') {
                throw ValidationException::withMessages(['subjects' => 'Seleccione únicamente materias de su carrera con contenido en el Excel.']);
            }
            $chosenFields = $fieldsBySubject[$subjectId] ?? array_keys($entry['values']);
            if ($chosenFields === [] || array_diff($chosenFields, array_keys($entry['values'])) !== []) {
                throw ValidationException::withMessages(['fields' => 'Seleccione al menos un campo con contenido del Excel en cada materia.']);
            }
        }
    }
}
