<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Application\Queries\SubjectLearningOutcomesViewData;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectLearningOutcomeBase;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Application\InProgressWork;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Support\CanonicalHasher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveSubjectLearningOutcomes
{
    public function __construct(
        private readonly SubjectLearningOutcomesViewData $viewData,
        private readonly ProcessLocks $locks,
        private readonly InProgressWork $work,
        private readonly RecordAuditEvent $audit,
        private readonly CanonicalHasher $hasher,
    ) {}

    /** @param array{source_name: string, source_locator: string, outcomes: list<array{id?: string, text: string}>, confirmed: bool, expected_fingerprint: string|null, confirm_purge?: bool} $data */
    public function execute(string $subjectId, array $data, User $actor, Request $request): Subject
    {
        $role = $this->viewData->role($request);

        return DB::transaction(function () use ($actor, $data, $request, $role, $subjectId): Subject {
            // Serializa también el primer registro: aún no habría cabecera que bloquear.
            $subject = Subject::query()->where('carrera_id', $role->carrera_id)->lockForUpdate()->findOrFail($subjectId);
            $this->locks->assertCareerEditable($subject->carrera_id);
            $base = $subject->learningOutcomeBase;
            $before = $base?->snapshot();
            $fingerprint = $before === null ? null : $this->hasher->hash($before);
            if ($data['expected_fingerprint'] !== $fingerprint) {
                throw new HttpResponseException(response()->json([
                    'code' => 'conflicto_resultados_aprendizaje',
                    'message' => 'Los resultados de aprendizaje cambiaron. Vuelva a abrirlos antes de guardar.',
                ], 409));
            }

            $knownIds = array_column($before['outcomes'] ?? [], 'id');
            $outcomes = [];
            foreach ($data['outcomes'] as $index => $outcome) {
                if (isset($outcome['id']) && ! in_array($outcome['id'], $knownIds, true)) {
                    throw ValidationException::withMessages([
                        "outcomes.{$index}.id" => 'El resultado de aprendizaje no pertenece a esta asignatura.',
                    ]);
                }
                $outcomes[] = [
                    'id' => $outcome['id'] ?? (string) Str::uuid(),
                    'text' => trim($outcome['text']),
                    'position' => $index + 1,
                ];
            }
            $content = [
                'source_name' => trim($data['source_name']),
                'source_locator' => trim($data['source_locator']),
                'outcomes' => $outcomes,
            ];
            if ($before !== null && $content === array_intersect_key($before, $content)) {
                return $subject;
            }

            return $this->work->replace($request, function () use ($actor, $base, $content, $fingerprint, $outcomes, $request, $role, $subject): Subject {
                $base ??= new SubjectLearningOutcomeBase(['asignatura_id' => $subject->id]);
                $base->fill([
                    'nombre_fuente' => $content['source_name'],
                    'localizador_fuente' => $content['source_locator'],
                    'confirmado_por' => $actor->id,
                    'confirmado_en' => now(),
                ])->save();
                // La colección actual se reemplaza en la misma transacción. Las revisiones
                // históricas conservan su fotografía; no referencian estas filas vivas.
                $base->outcomes()->delete();
                foreach ($outcomes as $outcome) {
                    $row = $base->outcomes()->make([
                        'texto_resultado' => $outcome['text'],
                        'posicion_resultado' => $outcome['position'],
                    ]);
                    $row->id = $outcome['id'];
                    $row->save();
                }
                $base->unsetRelation('outcomes')->unsetRelation('confirmedBy');
                $this->audit->execute(
                    actorId: $actor->id,
                    roleAssignmentId: $role->id,
                    action: 'academico.resultados_aprendizaje.confirmados',
                    resourceType: 'asignatura',
                    resourceId: $subject->id,
                    result: 'exito',
                    metadata: [
                        'career_id' => $subject->carrera_id,
                        'before_fingerprint' => $fingerprint,
                        'after_fingerprint' => $this->hasher->hash($base->snapshot()),
                        'outcome_count' => count($outcomes),
                    ],
                    correlationId: $request->attributes->getString('correlation_id') ?: null,
                );
                $subject->setRelation('learningOutcomeBase', $base);

                return $subject;
            }, $subject->carrera_id, reason: 'cambiaron los resultados de aprendizaje confirmados de una asignatura', subjectId: $subject->id);
        });
    }
}
