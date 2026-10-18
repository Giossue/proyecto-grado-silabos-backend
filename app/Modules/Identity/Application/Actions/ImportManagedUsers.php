<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Domain\PersonNameColumns;
use App\Modules\Identity\Presentation\Http\Requests\CreateManagedUserRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImportManagedUsers
{
    public const COLUMNS = [...PersonNameColumns::COLUMNS, 'correo' => 'Correo', 'rol' => 'Rol', 'carrera' => 'Carrera'];

    public function __construct(private readonly CreateManagedUser $createUser) {}

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return array{rows: list<array{line: int, values: array<string, string>, errors: list<string>}>, valid: bool, created: int}
     */
    public function execute(array $rows, User $actor, Request $request, bool $confirm): array
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $careers = Career::query()->where('activo', true)->get()->mapWithKeys(
            fn (Career $career): array => [$this->key($career->nombre) => $career->id],
        );
        $roles = collect(RoleCode::cases())->mapWithKeys(fn (RoleCode $role): array => [
            $this->key($role->label()) => $role->value,
            $this->key($role->value) => $role->value,
        ]);

        $seen = [];
        $checked = [];
        $payloads = [];
        foreach ($rows as $row) {
            [$values, $nameErrors] = PersonNameColumns::compose($row['values']);
            $row['values'] = $values;
            $email = mb_strtolower($values['correo']);
            $payload = [
                'nombre' => $values['nombre'],
                'correo_electronico' => $email,
                'role_code' => $roles->get($this->key($values['rol'])) ?? $values['rol'],
                'career_id' => $values['carrera'] === '' ? null : ($careers->get($this->key($values['carrera'])) ?? $values['carrera']),
            ];
            // Las partes del nombre ya las valida `compose`; aquí solo se comprueba el nombre unido.
            $rules = [
                'nombre' => ['required', 'string', 'max:180'],
                ...array_diff_key((new CreateManagedUserRequest)->rules(), ['nombre' => true, ...PersonNameColumns::COLUMNS]),
            ];
            $validator = Validator::make($payload, $rules, (new CreateManagedUserRequest)->messages());
            $errors = [...$nameErrors, ...$validator->errors()->all()];
            if (isset($seen[$email])) {
                $errors[] = "El correo se repite en la fila {$seen[$email]}.";
            }
            $seen[$email] ??= $row['line'];
            $checked[] = [...$row, 'errors' => array_values(array_unique($errors))];
            $payloads[] = $payload;
        }

        $valid = collect($checked)->every(fn (array $row): bool => $row['errors'] === []);
        $created = 0;
        if ($confirm && $valid) {
            DB::transaction(function () use ($payloads, $actor, $request, &$created): void {
                foreach ($payloads as $payload) {
                    $this->createUser->execute($payload, $actor, $request);
                    $created++;
                }
            });
        }

        return ['rows' => $checked, 'valid' => $valid, 'created' => $created];
    }

    private function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
