<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Identity\Application\Actions\AssignRole;
use App\Modules\Identity\Application\Actions\CreateManagedUser;
use App\Modules\Identity\Application\Actions\DeleteManagedUser;
use App\Modules\Identity\Application\Actions\ImportManagedUsers;
use App\Modules\Identity\Application\Actions\RemoveUserRole;
use App\Modules\Identity\Application\Actions\ResendManagedUserActivation;
use App\Modules\Identity\Application\Actions\SetUserStatus;
use App\Modules\Identity\Application\Actions\UpdateManagedUserProfile;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetReader;
use App\Modules\Identity\Application\Spreadsheet\SpreadsheetTemplate;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Domain\PersonNameColumns;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Identity\Presentation\Http\Requests\CreateManagedUserRequest;
use App\Modules\Identity\Presentation\Http\Requests\ImportSpreadsheetRequest;
use App\Modules\Identity\Presentation\Http\Requests\IndexUsersRequest;
use App\Modules\Identity\Presentation\Http\Requests\UpdateManagedUserRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagedUserController extends Controller
{
    public function index(IndexUsersRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $search = is_string($filters['q'] ?? null) ? trim($filters['q']) : null;
        $status = in_array($filters['status'] ?? null, ['active', 'pending', 'inactive'], true)
            ? $filters['status']
            : null;
        $role = in_array($filters['role'] ?? null, array_column(RoleCode::cases(), 'value'), true)
            ? $filters['role']
            : null;
        $career = is_string($filters['career'] ?? null) && Str::isUuid($filters['career'])
            ? $filters['career']
            : null;
        $users = User::query()
            ->select([
                'id',
                'nombre',
                'correo_electronico',
                'activo',
                'correo_verificado_en',
                'dos_factores_confirmado_en',
            ])
            ->when($search, fn (Builder $query, string $term) => $query->where(
                fn (Builder $searchQuery) => $searchQuery
                    ->whereRaw('nombre_usuario ILIKE ?', ['%'.addcslashes($term, '%_\\').'%'])
                    ->orWhereRaw('correo_electronico ILIKE ?', ['%'.addcslashes($term, '%_\\').'%']),
            ))
            ->when($status === 'active', fn (Builder $query) => $query
                ->where('activo', true)->whereNotNull('correo_verificado_en'))
            ->when($status === 'pending', fn (Builder $query) => $query
                ->where('activo', true)->whereNull('correo_verificado_en'))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('activo', false))
            ->when($role, fn (Builder $query, string $code) => $query->whereIn(
                'id',
                RoleAssignment::query()
                    ->effective()
                    ->where('rol', $code)
                    ->select('usuario_id'),
            ))
            ->when($career, fn (Builder $query, string $careerId) => $query->whereIn(
                'id',
                RoleAssignment::query()
                    ->effective()
                    ->where('carrera_id', $careerId)
                    ->select('usuario_id'),
            ))
            ->with(['roleAssignments' => fn ($query) => $query
                ->with(['career:id,nombre'])
                ->orderByDesc('asignado_en')])
            ->orderBy('nombre')
            ->paginate(20)
            ->withQueryString()
            ->through(function (User $user): array {
                $effective = $user->roleAssignments->where('activo', true);

                return [
                    'id' => $user->id,
                    'nombre' => $user->nombre,
                    'correo_electronico' => $user->correo_electronico,
                    'active' => $user->activo,
                    'pending_activation' => $user->correo_verificado_en === null,
                    'two_factor_enabled' => $user->dos_factores_confirmado_en !== null,
                    'roles' => $effective->map(fn (RoleAssignment $assignment): array => [
                        'id' => $assignment->id,
                        'code' => $assignment->rol,
                        'name' => $assignment->role->nombre_rol,
                        'career_name' => $assignment->career?->nombre,
                    ])->values()->all(),
                    'careers' => $effective
                        ->map(fn ($assignment) => $assignment->career?->nombre)
                        ->values()->all(),
                    'assignments' => $user->roleAssignments->map(fn ($assignment) => [
                        'id' => $assignment->id,
                        'role_name' => $assignment->role->nombre_rol,
                        'career_name' => $assignment->career?->nombre,
                        'active' => $assignment->activo,
                    ])->values()->all(),
                ];
            });

        $payload = [
            'users' => $users,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'role' => $role,
                'career' => $career,
            ],
            'roles' => $this->fixedRoles(),
            'careers' => Career::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ];

        return response()->json($payload);
    }

    public function store(CreateManagedUserRequest $request, CreateManagedUser $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $created = $action->execute([
            'nombre' => $request->fullName(),
            'correo_electronico' => $request->string('correo_electronico')->toString(),
            'role_code' => $request->string('role_code')->toString(),
            'career_id' => $request->filled('career_id') ? $request->string('career_id')->toString() : null,
        ], $actor, $request);

        return response()->json([
            'message' => 'Cuenta creada con su rol inicial.',
            'user' => ['id' => $created->id, 'nombre' => $created->nombre, 'correo_electronico' => $created->correo_electronico],
        ], 201);
    }

    public function importTemplate(SpreadsheetTemplate $template): StreamedResponse
    {
        Gate::authorize('create', User::class);

        $columns = array_keys(ImportManagedUsers::COLUMNS);

        return $template->download('plantilla-usuarios.xlsx', array_values(ImportManagedUsers::COLUMNS), [
            array_search('rol', $columns, true) => array_map(fn (RoleCode $role): string => $role->label(), RoleCode::cases()),
            array_search('carrera', $columns, true) => Career::query()->where('activo', true)->orderBy('nombre')->pluck('nombre')->all(),
        ], [
            ...PersonNameColumns::NOTES,
            'Correo: use el correo institucional; no puede repetirse.',
            'Todas las columnas son obligatorias, salvo Carrera para Administrador y Bibliotecario.',
            'Rol: elija una opción de la lista.',
            'Carrera: elija una de la lista para Coordinador y Docente; déjela vacía para Administrador y Bibliotecario.',
        ]);
    }

    public function import(ImportSpreadsheetRequest $request, SpreadsheetReader $reader, ImportManagedUsers $action): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::authorize('create', User::class);
        $rows = $reader->read($request->file('file'), ImportManagedUsers::COLUMNS);
        $result = $action->execute($rows, $actor, $request, $request->boolean('confirm'));

        return response()->json($result);
    }

    /** @return list<array{codigo: string, nombre: string}> */
    private function fixedRoles(): array
    {
        return array_map(
            fn (RoleCode $role): array => ['codigo' => $role->value, 'nombre' => $role->label()],
            RoleCode::cases(),
        );
    }

    public function resendActivation(User $user, Request $request, ResendManagedUserActivation $action): JsonResponse
    {
        Gate::authorize('managePending', $user);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($user, $actor, $request);

        $message = "Invitación de activación enviada a {$user->correo_electronico}.";

        return response()->json(['message' => $message]);
    }

    public function destroy(User $user, Request $request, DeleteManagedUser $action): JsonResponse
    {
        Gate::authorize('managePending', $user);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($user, $actor, $request);

        return response()->json(['message' => 'Cuenta eliminada.']);
    }

    public function removeRole(User $user, RoleAssignment $assignment, Request $request, RemoveUserRole $action): JsonResponse
    {
        Gate::authorize('updateProfileData', $user);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($user, $assignment, $actor, $request);

        return response()->json(['message' => 'Rol retirado.']);
    }

    public function update(
        User $user,
        UpdateManagedUserRequest $request,
        UpdateManagedUserProfile $updateProfile,
        AssignRole $assignRole,
        SetUserStatus $setStatus,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validated();
        $wasActive = $user->activo;

        DB::transaction(function () use ($actor, $assignRole, $request, $setStatus, $updateProfile, $user, $validated, $wasActive): void {
            $updateProfile->execute($user, $request->profileData(), $actor, $request);

            if (array_key_exists('role_code', $validated)) {
                $assignRole->execute($user, [
                    'role_code' => $validated['role_code'],
                    'career_id' => $validated['career_id'] ?? null,
                ], $actor, $request);
            }

            if (array_key_exists('active', $validated) && (bool) $validated['active'] !== $wasActive) {
                $setStatus->execute($user, (bool) $validated['active'], $actor, $request);
            }
        });

        return response()->json(['message' => 'Cuenta actualizada.']);
    }
}
