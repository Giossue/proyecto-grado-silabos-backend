<?php

namespace App\Modules\Academic\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Application\InstitutionalLogos;
use App\Modules\Identity\Application\ActiveRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImportFaculties
{
    public const COLUMNS = ['nombre' => 'Nombre de la facultad', 'campus' => 'Campus', 'logo' => 'Logo'];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly CreateAcademicRecord $createRecord,
    ) {}

    /** @return list<string> */
    public function campuses(Request $request): array
    {
        $this->authorize($request);

        return CampusLocation::values();
    }

    /**
     * @param  list<array{line: int, values: array<string, string>, images: list<string>}>  $rows
     * @return array{rows: list<array{line: int, values: array<string, string>, errors: list<string>, preview: string|null}>, valid: bool, created: int}
     */
    public function execute(array $rows, User $actor, Request $request, bool $confirm): array
    {
        $this->authorize($request);
        $campuses = collect(CampusLocation::values())->mapWithKeys(fn (string $value): array => [$this->key($value) => $value]);
        $taken = Faculty::query()->pluck('nombre')->map(fn (mixed $name): string => $this->key((string) $name))->flip();

        $seen = [];
        $checked = [];
        $payloads = [];
        $files = [];
        foreach ($rows as $row) {
            $values = $row['values'];
            $errors = [];
            $name = trim($values['nombre']);
            $campus = $campuses->get($this->key($values['campus']));
            if ($name === '' || mb_strlen($name) > 180) {
                $errors[] = 'El nombre es obligatorio y admite hasta 180 caracteres.';
            } elseif ($taken->has($this->key($name))) {
                $errors[] = 'Ya existe una facultad con este nombre.';
            } elseif (isset($seen[$this->key($name)])) {
                $errors[] = "El nombre se repite en la fila {$seen[$this->key($name)]}.";
            }
            $seen[$this->key($name)] ??= $row['line'];
            if ($campus === null) {
                $errors[] = 'El campus no es válido.';
            }

            $logo = null;
            $preview = null;
            if (count($row['images']) === 0) {
                $errors[] = 'Falta el logo: péguelo sobre la fila de esta facultad.';
            } elseif (count($row['images']) > 1) {
                $errors[] = 'Hay más de una imagen en esta fila.';
            } else {
                $path = tempnam(sys_get_temp_dir(), 'logo');
                file_put_contents($path, $row['images'][0]);
                $files[] = $path;
                $logo = new UploadedFile($path, 'logo.png', 'image/png', null, true);
                $logoErrors = Validator::make(['logo' => $logo], ['logo' => InstitutionalLogos::rules(InstitutionalLogos::FACULTY)], [
                    'logo.mimetypes' => 'El logo debe ser un PNG.',
                    'logo.max' => 'El logo no puede superar 4 MB.',
                ])->errors()->all();
                $errors = [...$errors, ...$logoErrors];
                $preview = 'data:image/png;base64,'.base64_encode($row['images'][0]);
            }

            $checked[] = ['line' => $row['line'], 'values' => $values, 'errors' => array_values(array_unique($errors)), 'preview' => $preview];
            $payloads[] = ['nombre' => $name, 'campus' => $campus, 'logo' => $logo];
        }

        $valid = collect($checked)->every(fn (array $row): bool => $row['errors'] === []);
        $created = 0;
        try {
            if ($confirm && $valid) {
                DB::transaction(function () use ($payloads, $actor, $request, &$created): void {
                    foreach ($payloads as $payload) {
                        $this->createRecord->execute('facultad', $payload, $actor, $request);
                        $created++;
                    }
                });
            }
        } finally {
            array_map('unlink', array_filter($files, 'file_exists'));
        }

        return ['rows' => $checked, 'valid' => $valid, 'created' => $created];
    }

    private function authorize(Request $request): void
    {
        if (! AcademicStructurePermissions::mayCreate($this->roles->resolve($request), 'facultad')) {
            throw new AuthorizationException('No puede importar facultades con el rol activo.');
        }
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
