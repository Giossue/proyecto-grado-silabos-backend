<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\SubjectProfileFields;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\FichaImport;
use App\Modules\Configuration\Domain\FreeText;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\FieldValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ApplyFichaImport
{
    private const BIBLIOGRAPHY_COLUMNS = ['Autor', 'Título', 'Año', 'Ciudad', 'Editorial', 'ISBN', 'Código'];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly ProcessLocks $locks,
        private readonly SubjectProfileFields $profile,
        private readonly RecordAuditEvent $audit,
        private readonly InProgressWork $work,
    ) {}

    /**
     * @param  list<string>  $subjectIds
     * @param  array<string, list<string>>|null  $fieldsBySubject
     */
    public function execute(FichaImport $import, array $subjectIds, User $actor, Request $request, ?array $fieldsBySubject = null): FichaImport
    {
        $role = $this->roles->resolve($request);
        abort_unless($actor->activo && AcademicStructurePermissions::isCareerContext($role) && $role?->carrera_id === $import->carrera_id, 403);
        $this->locks->assertCareerEditable($import->carrera_id);

        return $this->work->replace($request, fn (): FichaImport => DB::transaction(function () use ($import, $subjectIds, $actor, $role, $fieldsBySubject): FichaImport {
            $locked = FichaImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($locked->estado_importacion !== FichaImport::READY) {
                throw ValidationException::withMessages(['subjects' => 'La propuesta ya no está lista para aplicarse.']);
            }
            $fields = $this->profile->fields()->keyBy('clave');
            $applied = 0;
            foreach ($locked->propuesta_importacion['subjects'] ?? [] as $entry) {
                if (! in_array($entry['subject_id'], $subjectIds, true) || $entry['state'] !== 'lista') {
                    continue;
                }
                $chosenFields = $fieldsBySubject[$entry['subject_id']] ?? null;
                foreach ($entry['values'] as $key => $value) {
                    if (is_array($chosenFields) && ! in_array($key, $chosenFields, true)) {
                        continue;
                    }
                    $field = $fields->get($key);
                    if ($field === null) {
                        continue;
                    }
                    $document = match (true) {
                        is_array($value) && str_starts_with((string) $key, 'bibliografia') => FreeText::fromRows(
                            array_map(fn (string $column): array => ['key' => $column, 'label' => $column], self::BIBLIOGRAPHY_COLUMNS),
                            array_values(array_map(fn (string $item): array => array_combine(self::BIBLIOGRAPHY_COLUMNS, array_pad(array_slice(array_map('trim', explode('|', $item)), 0, count(self::BIBLIOGRAPHY_COLUMNS)), count(self::BIBLIOGRAPHY_COLUMNS), '')), $value)),
                        ),
                        is_array($value) => FreeText::fromRows([['key' => 't', 'label' => '']], array_values(array_map(fn (string $item): array => ['t' => $item], $value))),
                        default => FreeText::fromText((string) $value),
                    };
                    if (FreeText::isEmpty(FreeText::normalize($document))) {
                        continue;
                    }
                    FieldValue::query()->updateOrCreate(
                        ['asignatura_id' => $entry['subject_id'], 'definicion_campo_id' => $field->id],
                        ['valor' => FreeText::normalize($document)],
                    );
                }
                $applied++;
            }
            if ($applied === 0) {
                throw ValidationException::withMessages(['subjects' => 'Elija al menos una materia con ficha propuesta.']);
            }
            Storage::disk('private')->deleteDirectory("importaciones-ficha/{$locked->id}");
            $locked->update(['estado_importacion' => FichaImport::APPLIED, 'aplicado_en' => now(), 'documentos_importacion' => []]);
            $this->audit->execute($actor->id, $role->id, 'academico.ficha_importacion_aplicada', 'carrera', $locked->carrera_id, 'exito', ['subjects' => $applied]);

            return $locked;
        }), $import->carrera_id, null, 'Coordinación actualizó la ficha de la asignatura', null, $subjectIds);
    }
}
