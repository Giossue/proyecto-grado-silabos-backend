<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\CurricularUnit;
use App\Modules\Academic\Domain\CurriculumSystemFields;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Crea las materias de la malla de la carrera activa (y sus prerrequisitos y correquisitos) desde un Excel. */
class ImportCurriculum
{
    public const COLUMNS = [
        'codigo' => 'Código',
        'nombre' => 'Nombre de la materia',
        'ciclo' => 'Ciclo',
        'unidad' => 'Unidad de organización',
        'modalidad' => 'Modalidad',
        'acd' => 'Horas ACD',
        'ape' => 'Horas APE',
        'aa' => 'Horas AA',
        'creditos' => 'Créditos',
        'prerrequisitos' => 'Prerrequisitos (códigos)',
        'correquisitos' => 'Correquisitos (códigos)',
    ];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly CreateAcademicRecord $createRecord,
        private readonly MutateCurriculumBuilder $curriculum,
    ) {}

    /** @return array{units: list<string>, modalities: list<string>} */
    public function templateOptions(Request $request): array
    {
        $this->career($request);

        return [
            'units' => array_map(fn (CurricularUnit $unit): string => $unit->label(), CurricularUnit::cases()),
            'modalities' => StudyModality::values(),
        ];
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return array{rows: list<array<string, mixed>>, valid: bool, created: int}
     */
    public function execute(array $rows, User $actor, Request $request, bool $confirm): array
    {
        $career = $this->career($request);
        $stored = Subject::query()->where('carrera_id', $career->id)->get(['id', 'codigo_asignatura', 'ciclo']);
        $existing = $stored->mapWithKeys(fn (Subject $subject): array => [$this->key((string) $subject->codigo_asignatura) => (string) $subject->id]);
        $cycles = $stored->mapWithKeys(fn (Subject $subject): array => [$this->key((string) $subject->codigo_asignatura) => (int) $subject->ciclo])
            ->merge(collect($rows)->mapWithKeys(fn (array $row): array => [$this->key($row['values']['codigo']) => (int) $row['values']['ciclo']]));
        $modalities = collect(StudyModality::values())->mapWithKeys(fn (string $value): array => [$this->key($value) => $value]);
        $fileCodes = collect($rows)->mapWithKeys(fn (array $row): array => [$this->key($row['values']['codigo']) => $row['line']]);

        $seen = [];
        $checked = [];
        $payloads = [];
        foreach ($rows as $row) {
            $values = $row['values'];
            $errors = [];
            $code = trim($values['codigo']);
            $codeKey = $this->key($code);
            if ($code === '' || mb_strlen($code) > 80) {
                $errors[] = 'El código es obligatorio y admite hasta 80 caracteres.';
            } elseif ($existing->has($codeKey)) {
                $errors[] = 'La malla ya tiene una materia con este código.';
            } elseif (isset($seen[$codeKey])) {
                $errors[] = "El código se repite en la fila {$seen[$codeKey]}.";
            }
            $seen[$codeKey] ??= $row['line'];
            $name = trim($values['nombre']);
            if ($name === '' || mb_strlen($name) > 180) {
                $errors[] = 'El nombre es obligatorio y admite hasta 180 caracteres.';
            }
            $cycle = trim($values['ciclo']);
            if (! ctype_digit($cycle) || (int) $cycle < 1 || (int) $cycle > $career->cantidad_ciclos_malla) {
                $errors[] = "El ciclo debe estar entre 1 y {$career->cantidad_ciclos_malla}.";
            }
            $unit = CurricularUnit::tryFromText($values['unidad']);
            if ($unit === null) {
                $errors[] = 'La unidad de organización no es válida.';
            }
            $modality = trim($values['modalidad']) === '' ? null : $modalities->get($this->key($values['modalidad']));
            if (trim($values['modalidad']) !== '' && $modality === null) {
                $errors[] = 'La modalidad no es válida.';
            }
            $numbers = [];
            foreach (['acd' => 'Horas ACD', 'ape' => 'Horas APE', 'aa' => 'Horas AA', 'creditos' => 'Créditos'] as $key => $label) {
                $value = str_replace(',', '.', trim($values[$key]));
                if (! is_numeric($value) || (float) $value < 0) {
                    $errors[] = "{$label} debe ser un número mayor o igual a 0.";
                }
                $numbers[$key] = is_numeric($value) ? $value + 0 : 0;
            }
            $relations = [];
            foreach (['prerrequisitos' => 'prerrequisito', 'correquisitos' => 'correquisito'] as $column => $type) {
                foreach ($this->codes($values[$column]) as $requirement) {
                    $requirementKey = $this->key($requirement);
                    if ($requirementKey === $codeKey) {
                        $errors[] = 'Una materia no puede ser requisito de sí misma.';
                    } elseif (! $existing->has($requirementKey) && ! $fileCodes->has($requirementKey)) {
                        $errors[] = "El requisito {$requirement} no está en la malla ni en el archivo.";
                    } elseif ($type === 'prerrequisito' && (int) $cycles->get($requirementKey) >= (int) $cycle) {
                        $errors[] = "El prerrequisito {$requirement} debe estar en un ciclo anterior.";
                    } elseif ($type === 'correquisito' && (int) $cycles->get($requirementKey) !== (int) $cycle) {
                        $errors[] = "El correquisito {$requirement} debe estar en el mismo ciclo.";
                    } else {
                        $relations[] = ['code' => $requirementKey, 'type' => $type];
                    }
                }
            }

            $checked[] = [...$row, 'errors' => $errors];
            $payloads[] = [
                'key' => $codeKey,
                'relations' => $relations,
                'data' => [
                    'code' => $code, 'nombre' => $name, 'cycle' => (int) $cycle,
                    'organization_unit' => $unit?->value, 'modality' => $modality,
                    'horas_ac' => $numbers['acd'], 'horas_pae' => $numbers['ape'], 'horas_aa' => $numbers['aa'],
                    'creditos' => $numbers['creditos'],
                    'horas_totales' => (int) round(CurriculumSystemFields::totalHours(['horas_ac' => $numbers['acd'], 'horas_pae' => $numbers['ape'], 'horas_aa' => $numbers['aa']])),
                ],
            ];
        }

        $valid = collect($checked)->every(fn (array $row): bool => $row['errors'] === []);
        $created = 0;
        if ($confirm && $valid) {
            DB::transaction(function () use ($payloads, $existing, $career, $actor, $request, &$created): void {
                $ids = $existing->all();
                foreach ($payloads as $payload) {
                    $subject = $this->createRecord->execute('asignatura', $payload['data'], $actor, $request);
                    $ids[$payload['key']] = (string) $subject->getKey();
                    $created++;
                }
                foreach ($payloads as $payload) {
                    foreach ($payload['relations'] as $relation) {
                        $this->curriculum->createRequirement((string) $career->id, [
                            'subject_id' => $ids[$payload['key']],
                            'requirement_id' => $ids[$relation['code']],
                            'type' => $relation['type'],
                        ], $actor, $request);
                    }
                }
            });
        }

        return ['rows' => $checked, 'valid' => $valid, 'created' => $created];
    }

    private function career(Request $request): Career
    {
        $role = $this->roles->resolve($request);
        if (! $role instanceof RoleAssignment || ! AcademicStructurePermissions::isCareerContext($role) || $role->carrera_id === null
            || ! AcademicStructurePermissions::mayCreate($role, 'asignatura')) {
            throw new AuthorizationException('Solo la coordinación de la carrera puede importar la malla.');
        }

        return Career::query()->findOrFail($role->carrera_id);
    }

    /** @return list<string> */
    private function codes(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $value) ?: []), fn (string $code): bool => $code !== ''));
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
