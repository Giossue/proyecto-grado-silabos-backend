<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Application\Contracts\RoleEligibility;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ActiveRole
{
    public function __construct(private readonly RoleEligibility $eligibility) {}

    public function resolve(Request $request): ?RoleAssignment
    {
        $cached = $request->attributes->get('active_role');

        if ($cached instanceof RoleAssignment) {
            return $cached;
        }

        $user = $request->user();
        $assignmentId = $request->session()->get('active_role_assignment_id');

        if (! $user instanceof User || ! $user->activo) {
            return null;
        }

        if (! is_string($assignmentId)) {
            return $this->activateSoleRole($user, $request);
        }

        $assignment = RoleAssignment::query()
            ->effective()
            ->where('usuario_id', $user->id)
            ->with(['career:id,nombre,activo'])
            ->find($assignmentId);

        if ($assignment !== null && $this->isEligible($assignment)) {
            $request->attributes->set('active_role', $assignment);

            return $assignment;
        }

        $request->session()->forget('active_role_assignment_id');

        return $this->activateSoleRole($user, $request);
    }

    public function hasRole(Request $request, RoleCode $role): bool
    {
        return $this->resolve($request)?->role->codigo_rol === $role->value;
    }

    /** @return Collection<int, RoleAssignment> */
    public function eligible(User $user): Collection
    {
        if (! $user->activo) {
            return collect();
        }

        return RoleAssignment::query()
            ->effective()
            ->where('usuario_id', $user->id)
            ->with(['career:id,nombre,activo'])
            ->orderBy('asignado_en')
            ->orderBy('id')
            ->get()
            ->filter(fn (RoleAssignment $assignment): bool => $this->isEligible($assignment))
            ->values();
    }

    private function activateSoleRole(User $user, Request $request): ?RoleAssignment
    {
        $eligible = $this->eligible($user);

        if ($eligible->count() !== 1) {
            return null;
        }

        $assignment = $eligible->firstOrFail();
        $request->session()->put('active_role_assignment_id', $assignment->id);
        $request->attributes->set('active_role', $assignment);

        return $assignment;
    }

    public function isEligible(RoleAssignment $assignment): bool
    {
        return $this->eligibility->allows(
            $assignment->usuario_id,
            $assignment->role->codigo_rol,
            $assignment->carrera_id,
        );
    }
}
