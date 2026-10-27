<?php

namespace App\Modules\Operations\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Application\Actions\SelectActiveRole;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Application\NotificationDestination;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Operations\Presentation\Http\Requests\MarkAllNotificationsReadRequest;
use App\Modules\Operations\Presentation\Http\Requests\MarkNotificationReadRequest;
use App\Modules\Operations\Presentation\Http\Requests\ViewNotificationsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class NotificationController extends Controller
{
    public function __construct(
        private readonly ActiveRole $roles,
        private readonly NotificationDestination $destinations,
    ) {}

    public function index(ViewNotificationsRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $active = $this->roles->resolve($request);
        $eligible = $this->roles->eligible($actor);
        $status = $request->string('status')->toString() ?: 'all';
        $query = InternalNotification::query()->where('usuario_id', $actor->id)->inCurrentPeriod();
        if ($status === 'unread') {
            $query->whereNull('leido_en');
        }

        $notifications = $query->latest('notificado_en')->paginate(20)->withQueryString()
            ->through(fn (InternalNotification $notification): array => $this->notificationPayload($notification, $actor, $active, $eligible));
        $payload = [
            'filters' => ['status' => $status],
            'notifications' => $notifications,
            'unread_count' => InternalNotification::query()
                ->where('usuario_id', $actor->id)
                ->inCurrentPeriod()
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

    public function open(
        InternalNotification $notification,
        MarkNotificationReadRequest $request,
        SelectActiveRole $selectRole,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($notification->leido_en === null) {
            $notification->update(['leido_en' => now()]);
        }
        $active = $this->roles->resolve($request);
        $destination = $this->destinations->resolve($notification, $actor, $active);
        if ($destination === null) {
            return response()->json([
                'path' => null,
                'role_changed' => false,
                'message' => 'Este aviso ya no tiene una pantalla disponible para sus roles actuales.',
            ]);
        }
        $target = $destination['assignment'];
        $changed = $target !== null && $target->id !== $active?->id;
        if ($changed) {
            $selectRole->execute($actor, $target->id, $request);
        }

        return response()->json(['path' => $destination['path'], 'role_changed' => $changed]);
    }

    public function markAllRead(MarkAllNotificationsReadRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        InternalNotification::query()
            ->where('usuario_id', $actor->id)
            ->inCurrentPeriod()
            ->whereNull('leido_en')
            ->update(['leido_en' => now()]);

        return response()->json(['message' => 'Todas las notificaciones quedaron marcadas como leídas.']);
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return array{id: string, tipo: string, titulo: string, mensaje: string, leido_en: string|null, notificado_en: string, has_destination: bool, context: string|null}
     */
    private function notificationPayload(InternalNotification $notification, User $actor, ?RoleAssignment $active, Collection $eligible): array
    {
        $destination = $this->destinations->resolve($notification, $actor, $active, $eligible);

        return [
            'id' => $notification->id,
            'tipo' => $notification->tipo,
            'titulo' => $notification->titulo,
            'mensaje' => $notification->mensaje,
            'leido_en' => $notification->leido_en?->toIso8601String(),
            'notificado_en' => $notification->notificado_en->toIso8601String(),
            'has_destination' => $destination !== null,
            'context' => NotificationDestination::contextLabel($destination['assignment'] ?? null),
        ];
    }
}
