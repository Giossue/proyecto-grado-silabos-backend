<?php

namespace App\Modules\Identity\Application\Actions;

use App\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\CreateCareerTeacherRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ImportCareerTeachers
{
    public const COLUMNS = ['nombre' => 'Nombre', 'correo' => 'Correo'];

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
            $email = mb_strtolower($row['values']['correo']);
            $payload = ['nombre' => $row['values']['nombre'], 'correo_electronico' => $email];
            $rules = array_intersect_key((new CreateCareerTeacherRequest)->rules(), $payload);
            $errors = Validator::make($payload, $rules)->errors()->all();
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
