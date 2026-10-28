<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Domain\PersonNameColumns;
use App\Modules\Identity\Presentation\Http\Requests\CreateCareerTeacherRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ImportCareerTeachers
{
    public const COLUMNS = [...PersonNameColumns::COLUMNS, 'correo' => 'Correo'];

    public function __construct(private readonly CreateCareerTeacher $createTeacher) {}

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @return array{rows: list<array{line: int, values: array<string, string>, errors: list<string>}>, valid: bool, created: int}
     */
    public function execute(array $rows, User $actor, Request $request, bool $confirm): array
    {
        Gate::forUser($actor)->authorize('createCareerTeacher', User::class);

        $seen = [];
        $checked = [];
        $payloads = [];
        foreach ($rows as $row) {
            [$values, $nameErrors] = PersonNameColumns::compose($row['values']);
            $row['values'] = $values;
            $email = mb_strtolower($values['correo']);
            $payload = ['nombre' => $values['nombre'], 'correo_electronico' => $email];
            $rules = [
                'nombre' => ['required', 'string', 'max:180'],
                ...array_intersect_key((new CreateCareerTeacherRequest)->rules(), ['correo_electronico' => true]),
            ];
            $blocked = $this->createTeacher->blockedReason(User::query()->whereRaw('lower(correo_electronico) = ?', [$email])->first());
            $errors = [...$nameErrors, ...Validator::make($payload, $rules)->errors()->all(), ...($blocked === null ? [] : [$blocked])];
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
                    $this->createTeacher->execute($payload, $actor, $request);
                    $created++;
                }
            });
        }

        return ['rows' => $checked, 'valid' => $valid, 'created' => $created];
    }
}
