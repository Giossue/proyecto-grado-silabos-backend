<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\Api\ConfirmCurrentPasswordRequest;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionManagementController extends Controller
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $sessions = DB::table((string) config('session.table', 'sesiones'))
            ->where('user_id', $user->id)
            ->latest('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'device' => $this->device((string) $session->user_agent),
                'last_activity' => now()->setTimestamp((int) $session->last_activity)->toIso8601String(),
                'current' => hash_equals((string) $session->id, $request->session()->getId()),
            ])
            ->values();

        return response()->json(['sessions' => $sessions]);
    }

    public function destroy(string $session, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_if(hash_equals($session, $request->session()->getId()), 422, 'La sesión actual se cierra desde la opción Cerrar sesión.');

        DB::table((string) config('session.table', 'sesiones'))
            ->where('id', $session)
            ->where('user_id', $user->id)
            ->delete();

        $this->record($request, $user, 'sesion.remota_cerrada', ['session_id_hash' => hash('sha256', $session)]);

        return response()->json(['message' => 'La sesión seleccionada fue cerrada.']);
    }

    public function destroyOthers(ConfirmCurrentPasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $deleted = DB::table((string) config('session.table', 'sesiones'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $this->record($request, $user, 'sesiones.remotas_cerradas', ['closed_sessions' => $deleted]);

        return response()->json([
            'message' => $deleted === 1 ? 'Se cerró una sesión adicional.' : "Se cerraron {$deleted} sesiones adicionales.",
            'closed_sessions' => $deleted,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function record(Request $request, User $user, string $action, array $metadata): void
    {
        $this->audit->execute(
            actorId: $user->id,
            roleAssignmentId: null,
            action: $action,
            resourceType: 'usuario',
            resourceId: $user->id,
            result: 'exito',
            metadata: $metadata,
            correlationId: $request->attributes->getString('correlation_id') ?: null,
        );
    }

    private function device(string $agent): string
    {
        $browser = match (true) {
            Str::contains($agent, ['Edg/']) => 'Edge',
            Str::contains($agent, ['Firefox/']) => 'Firefox',
            Str::contains($agent, ['Chrome/']) => 'Chrome',
            Str::contains($agent, ['Safari/']) => 'Safari',
            default => 'Navegador desconocido',
        };
        $platform = match (true) {
            Str::contains($agent, ['Android']) => 'Android',
            Str::contains($agent, ['iPhone', 'iPad']) => 'iOS',
            Str::contains($agent, ['Windows']) => 'Windows',
            Str::contains($agent, ['Macintosh']) => 'macOS',
            Str::contains($agent, ['Linux']) => 'Linux',
            default => 'dispositivo desconocido',
        };

        return "{$browser} en {$platform}";
    }
}
