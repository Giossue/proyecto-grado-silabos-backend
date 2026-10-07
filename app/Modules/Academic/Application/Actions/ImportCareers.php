<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportCareers
{
    public const COLUMNS = ['facultad' => 'Facultad', 'modalidad' => 'Modalidad', 'codigo' => 'Código de carrera', 'nombre' => 'Nombre de la carrera', 'ciclos' => 'Número de ciclos'];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly CreateAcademicRecord $createRecord,
    ) {}

    /** @return array{faculties: list<string>, modalities: list<string>} */
    public function templateOptions(Request $request): array
    {
        $this->authorize($request);

        return [
            'faculties' => Faculty::query()->where('activo', true)->orderBy('nombre')->pluck('nombre')->all(),
            'modalities' => StudyModality::values(),
        ];
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return array{rows: list<array{line: int, values: array<string, string>, errors: list<string>}>, valid: bool, created: int}
     */
    public function execute(array $rows, User $actor, Request $request, bool $confirm): array
    {
        $this->authorize($request);
        $faculties = Faculty::query()->where('activo', true)->get()->mapWithKeys(fn (Faculty $faculty): array => [$this->key($faculty->nombre) => $faculty->id]);
        $modalities = collect(StudyModality::values())->mapWithKeys(fn (string $value): array => [$this->key($value) => $value]);
        $taken = Career::query()->pluck('codigo_carrera')->map(fn (mixed $code): string => $this->key((string) $code))->flip();

        $seen = [];
        $checked = [];
        $payloads = [];
        foreach ($rows as $row) {
            $values = $row['values'];
            $errors = [];
            $facultyId = $faculties->get($this->key($values['facultad']));
            $modality = $modalities->get($this->key($values['modalidad']));
            $code = trim($values['codigo']);
            $name = trim($values['nombre']);
            $cycles = $values['ciclos'];
            if ($facultyId === null) {
                $errors[] = 'La facultad no existe o está inactiva.';
            }
            if ($modality === null) {
                $errors[] = 'La modalidad no es válida.';
            }
            if ($code === '' || mb_strlen($code) > 80) {
                $errors[] = 'El código es obligatorio y admite hasta 80 caracteres.';
            } elseif ($taken->has($this->key($code))) {
                $errors[] = 'Ya existe una carrera con este código.';
            } elseif (isset($seen[$this->key($code)])) {
                $errors[] = "El código se repite en la fila {$seen[$this->key($code)]}.";
            }
            $seen[$this->key($code)] ??= $row['line'];
            if ($name === '' || mb_strlen($name) > 180) {
                $errors[] = 'El nombre es obligatorio y admite hasta 180 caracteres.';
            }
            if (! ctype_digit($cycles) || (int) $cycles < 1 || (int) $cycles > 30) {
                $errors[] = 'El número de ciclos debe estar entre 1 y 30.';
            }
            $checked[] = [...$row, 'errors' => $errors];
            $payloads[] = ['faculty_id' => $facultyId, 'modality' => $modality, 'code' => $code, 'nombre' => $name, 'cycle_count' => (int) $cycles];
        }

        $valid = collect($checked)->every(fn (array $row): bool => $row['errors'] === []);
        $created = 0;
        if ($confirm && $valid) {
            DB::transaction(function () use ($payloads, $actor, $request, &$created): void {
                foreach ($payloads as $payload) {
                    $this->createRecord->execute('carrera', $payload, $actor, $request);
                    $created++;
                }
            });
        }

        return ['rows' => $checked, 'valid' => $valid, 'created' => $created];
    }

    private function authorize(Request $request): void
    {
        if (! AcademicStructurePermissions::mayCreate($this->roles->resolve($request), 'carrera')) {
            throw new AuthorizationException('No puede importar carreras con el rol activo.');
        }
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
