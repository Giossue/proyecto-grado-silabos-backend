<?php

namespace App\Modules\Academic\Application\Queries;

use App\Modules\Academic\Application\CurricularImportCapabilities;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularImport;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\SubjectLearningOutcomeBase;
use App\Modules\Configuration\Application\ProjectDataUsage;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\ProcessLocks;
use App\Support\CanonicalHasher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final class CurricularImportsViewData
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly ProcessLocks $locks,
        private readonly CanonicalHasher $hasher,
        private readonly ConfirmedCurricularValues $confirmedValues,
    ) {}

    public function role(Request $request): RoleAssignment
    {
        $role = $this->roles->resolve($request);
        if (! $role instanceof RoleAssignment || ! AcademicStructurePermissions::isCareerContext($role)) {
            throw new AuthorizationException('Solo la coordinación activa puede gestionar las importaciones de su carrera.');
        }

        return $role;
    }

    public function find(string $id, Request $request): CurricularImport
    {
        return CurricularImport::query()->where('carrera_id', $this->role($request)->carrera_id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public function index(Request $request): array
    {
        $careerId = $this->role($request)->carrera_id;

        return [
            'imports' => CurricularImport::query()->where('carrera_id', $careerId)->orderByDesc('solicitado_en')->orderByDesc('id')
                ->get(['id', 'estado_importacion', 'solicitado_en', 'aplicado_en', 'mensaje_error_importacion'])->map(fn (CurricularImport $import): array => [
                    'id' => $import->id, 'status' => $import->estado_importacion,
                    'created_at' => $import->solicitado_en->toISOString(), 'applied_at' => $import->aplicado_en?->toISOString(),
                    'error' => $import->mensaje_error_importacion,
                ])->all(),
            'subjects' => Subject::query()->where('carrera_id', $careerId)->orderBy('nombre')->get(['id', 'nombre', 'codigo_asignatura'])
                ->map(fn (Subject $subject): array => ['id' => $subject->id, 'name' => $subject->nombre, 'code' => $subject->codigo_asignatura])->all(),
            'current_values' => $this->confirmedValues->forCareer($careerId),
            // @ProyectoCarrera que usa la plantilla (I-151, I-155).
            'concepts' => array_values(array_map(
                fn (array $definition): array => ['key' => $definition['key'], 'label' => $definition['label'], 'type' => $definition['type'], 'scope' => $definition['scope'], 'sections' => $definition['sections']],
                ProjectDataUsage::used(),
            )),
            'lock_reason' => $this->locks->careerLockReason($careerId),
            'capabilities' => app(CurricularImportCapabilities::class)->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(CurricularImport $import): array
    {
        $lockReason = $this->locks->careerLockReason($import->carrera_id);
        // Tras guardar o descartar, los documentos temporales ya no existen (I-152).
        $available = AcademicSource::query()->where('carrera_id', $import->carrera_id)
            ->whereIn('id', array_column($import->documentos_importacion, 'source_id'))->pluck('id')->all();
        $originalNames = StoredObject::query()->where('carrera_id', $import->carrera_id)
            ->whereIn('id', array_column($import->documentos_importacion, 'original_object_id'))
            ->pluck('nombre_logico', 'id');

        return [
            'id' => $import->id, 'career_id' => $import->carrera_id, 'status' => $import->estado_importacion,
            'documents' => array_map(fn (array $document): array => [...$document,
                'original_name' => $originalNames->get($document['original_object_id'] ?? ''),
                'original_url' => isset($document['original_object_id']) && in_array($document['source_id'] ?? null, $available, true)
                    ? route('api.v1.coordination.curricular-imports.documents.original', ['import' => $import->id, 'source' => $document['source_id']]) : null,
            ], $import->documentos_importacion), 'proposal' => $this->proposalWithCurrentValues($import),
            'applied_items' => $import->seleccion_aplicada ?? [],
            'fingerprint' => $this->fingerprint($import), 'error' => $import->mensaje_error_importacion,
            'editable' => $lockReason === null && $import->estado_importacion === 'lista', 'lock_reason' => $lockReason,
        ];
    }

    public function fingerprint(CurricularImport $import): string
    {
        return $this->hasher->hash([
            'id' => $import->id, 'status' => $import->estado_importacion,
            'documents' => $import->documentos_importacion, 'proposal' => $import->propuesta_importacion,
            // También detecta otra importación aplicada después de consultar esta.
            'current_values' => CurricularValue::query()->where('carrera_id', $import->carrera_id)->orderBy('id')->get()->toArray(),
            'previous_outcomes' => SubjectLearningOutcomeBase::query()->with('outcomes')
                ->whereHas('subject', fn ($query) => $query->where('carrera_id', $import->carrera_id))
                ->orderBy('id')->get()->toArray(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function proposalWithCurrentValues(CurricularImport $import): ?array
    {
        $proposal = $import->propuesta_importacion;
        if ($proposal === null) {
            return null;
        }
        $definitions = array_column($proposal['definitions'], null, 'key');
        $current = CurricularValue::query()->with('concept')->where('carrera_id', $import->carrera_id)->get()
            ->keyBy(fn (CurricularValue $value): string => $value->concept->clave_concepto.':'.($value->asignatura_id ?? 'career'));
        $previousOutcomes = SubjectLearningOutcomeBase::query()->with('outcomes')
            ->whereHas('subject', fn ($query) => $query->where('carrera_id', $import->carrera_id))
            ->get()->keyBy('asignatura_id');
        foreach ($proposal['values'] as &$row) {
            $definition = $definitions[$row['concept_key']] ?? [];
            $unresolved = ($definition['scope'] ?? null) === 'asignatura' && $row['subject_id'] === null;
            $value = $unresolved ? null : $current->get($row['concept_key'].':'.($row['subject_id'] ?? 'career'));
            $legacy = $value === null && ! $unresolved && $row['subject_id'] !== null
                && $row['concept_key'] === 'resultados_aprendizaje' && ($definition['type'] ?? null) === 'lista'
                && ($definition['scope'] ?? null) === 'asignatura'
                ? $previousOutcomes->get($row['subject_id']) : null;
            $currentValue = $value !== null ? $value->valor_curricular
                : ($legacy !== null ? $legacy->outcomes->pluck('texto_resultado')->values()->all() : null);
            $row['current_value'] = $currentValue;
            $row['change'] = $unresolved ? null : ($currentValue === null ? 'new'
                : ($this->hasher->hash($currentValue) === $this->hasher->hash($row['value']) ? 'unchanged' : 'replace'));
        }

        return $proposal;
    }
}
