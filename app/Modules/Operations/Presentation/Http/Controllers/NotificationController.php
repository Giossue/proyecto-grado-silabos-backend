<?php

namespace App\Modules\Operations\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiExecution;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Operations\Presentation\Http\Requests\MarkAllNotificationsReadRequest;
use App\Modules\Operations\Presentation\Http\Requests\MarkNotificationReadRequest;
use App\Modules\Operations\Presentation\Http\Requests\ViewNotificationsRequest;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function index(ViewNotificationsRequest $request, ActiveRole $roles): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $roleCode = $roles->resolve($request)?->role->codigo_rol;
        $status = $request->string('status')->toString() ?: 'all';
        $query = InternalNotification::query()->where('usuario_id', $actor->id);
        if ($status === 'unread') {
            $query->whereNull('leido_en');
        }

        $notifications = $query->latest('notificado_en')->paginate(20)->withQueryString()
            ->through(fn (InternalNotification $notification): array => $this->notificationPayload($notification, $actor, $roleCode));
        $payload = [
            'filters' => ['status' => $status],
            'notifications' => $notifications,
            'unread_count' => InternalNotification::query()
                ->where('usuario_id', $actor->id)
                ->whereNull('leido_en')
                ->count(),
        ];

        return response()->json($payload);
    }

    public function markRead(
        InternalNotification $notification,
        MarkNotificationReadRequest $request,
    ): JsonResponse {
        if ($notification->leido_en === null) {
            $notification->update(['leido_en' => now()]);
        }

        return response()->json(['message' => 'Notificación marcada como leída.']);
    }

    public function markAllRead(MarkAllNotificationsReadRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        InternalNotification::query()
            ->where('usuario_id', $actor->id)
            ->whereNull('leido_en')
            ->update(['leido_en' => now()]);

        return response()->json(['message' => 'Todas las notificaciones quedaron marcadas como leídas.']);
    }

    /** @return array{id: string, tipo: string, titulo: string, mensaje: string, leido_en: string|null, notificado_en: string, resource_path: string|null} */
    private function notificationPayload(InternalNotification $notification, User $actor, ?string $roleCode): array
    {
        return [
            'id' => $notification->id,
            'tipo' => $notification->tipo,
            'titulo' => $notification->titulo,
            'mensaje' => $notification->mensaje,
            'leido_en' => $notification->leido_en?->toIso8601String(),
            'notificado_en' => $notification->notificado_en->toIso8601String(),
            'resource_path' => $this->resourcePath($notification, $actor) ?? $this->scopePath($notification, $roleCode),
        ];
    }

    private function resourcePath(InternalNotification $notification, User $actor): ?string
    {
        if ($notification->recurso_id === null) {
            return null;
        }
        if ($notification->tipo_recurso === 'silabo') {
            $syllabus = Syllabus::query()->find($notification->recurso_id);
            if ($syllabus !== null && $actor->can('review', $syllabus)) {
                $revision = $syllabus->revisions()->latest('numero_revision')->first();

                return $revision === null
                    ? null
                    : "/coordinacion/revisiones/{$revision->id}";
            }

            return $syllabus !== null && $actor->can('view', $syllabus)
                ? "/docente/mis-silabos/{$syllabus->id}"
                : null;
        }
        if ($notification->tipo_recurso === 'artefacto_exportacion') {
            $artifact = ExportArtifact::query()->with('revision')->find($notification->recurso_id);

            return $artifact !== null && $actor->can('view', $artifact)
                ? "/revisiones/{$artifact->revision->id}/documentos"
                : null;
        }
        if ($notification->tipo_recurso === 'ejecucion_ia') {
            $execution = AiExecution::query()->with(['syllabus', 'field'])->find($notification->recurso_id);

            return $execution !== null && $actor->can('edit', $execution->syllabus)
                ? "/docente/mis-silabos/{$execution->syllabus->id}"
                : null;
        }

        return null;
    }

    /**
     * Avisos de I-120 sobre procesos, convocatorias y cuentas: llevan a la pantalla donde
     * el rol activo actúa. Un rol que no corresponde no recibe enlace.
     */
    private function scopePath(InternalNotification $notification, ?string $roleCode): ?string
    {
        $coordinator = $roleCode === RoleCode::Coordinator->value;
        $teacher = $roleCode === RoleCode::Teacher->value;

        return match ($notification->tipo_recurso) {
            'proceso_silabos', 'convocatoria' => $coordinator ? '/coordinacion/convocatorias' : ($teacher ? '/docente/mis-silabos' : null),
            'carrera' => $notification->tipo === 'coordinacion.asignada'
                ? ($coordinator ? '/coordinacion/revisiones' : null)
                : ($teacher ? '/docente/mis-silabos' : null),
            'asignacion_rol' => '/rol',
            'usuario' => match ($roleCode) {
                RoleCode::Administrator->value => "/admin/usuarios/{$notification->recurso_id}",
                RoleCode::Coordinator->value => '/coordinacion/asignaciones-docentes',
                default => null,
            },
            default => null,
        };
    }
}
