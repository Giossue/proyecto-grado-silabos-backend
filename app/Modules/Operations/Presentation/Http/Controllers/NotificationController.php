<?php

namespace App\Modules\Operations\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiExecution;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Operations\Presentation\Http\Requests\MarkAllNotificationsReadRequest;
use App\Modules\Operations\Presentation\Http\Requests\MarkNotificationReadRequest;
use App\Modules\Operations\Presentation\Http\Requests\ViewNotificationsRequest;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(ViewNotificationsRequest $request): Response|JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $status = $request->string('status')->toString() ?: 'all';
        $query = InternalNotification::query()->where('usuario_id', $actor->id);
        if ($status === 'unread') {
            $query->whereNull('leido_en');
        }

        $notifications = $query->latest('notificado_en')->paginate(20)->withQueryString()
            ->through(fn (InternalNotification $notification): array => $this->notificationPayload($notification, $actor));
        $payload = [
            'filters' => ['status' => $status],
            'notifications' => $notifications,
            'unread_count' => InternalNotification::query()
                ->where('usuario_id', $actor->id)
                ->whereNull('leido_en')
                ->count(),
        ];

        return $request->expectsJson()
            ? response()->json($payload)
            : Inertia::render('Notifications/Index', $payload);
    }

    public function markRead(
        InternalNotification $notification,
        MarkNotificationReadRequest $request,
    ): RedirectResponse|JsonResponse {
        if ($notification->leido_en === null) {
            $notification->update(['leido_en' => now()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Notificación marcada como leída.']);
        }

        return back()->with('success', 'Notificación marcada como leída.');
    }

    public function markAllRead(MarkAllNotificationsReadRequest $request): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        InternalNotification::query()
            ->where('usuario_id', $actor->id)
            ->whereNull('leido_en')
            ->update(['leido_en' => now()]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Todas las notificaciones quedaron marcadas como leídas.']);
        }

        return back()->with('success', 'Todas las notificaciones quedaron marcadas como leídas.');
    }

    /** @return array{id: string, tipo: string, titulo: string, mensaje: string, leido_en: string|null, notificado_en: string, resource_path: string|null} */
    private function notificationPayload(InternalNotification $notification, User $actor): array
    {
        return [
            'id' => $notification->id,
            'tipo' => $notification->tipo,
            'titulo' => $notification->titulo,
            'mensaje' => $notification->mensaje,
            'leido_en' => $notification->leido_en?->toIso8601String(),
            'notificado_en' => $notification->notificado_en->toIso8601String(),
            'resource_path' => $this->resourcePath($notification, $actor),
        ];
    }

    private function resourcePath(InternalNotification $notification, User $actor): ?string
    {
        if ($notification->recurso_id === null) {
            return null;
        }
        if ($notification->tipo_recurso === 'silabo') {
            $syllabus = Syllabus::query()->find($notification->recurso_id);

            return $syllabus !== null && $actor->can('view', $syllabus)
                ? "/mis-silabos/{$syllabus->id}"
                : null;
        }
        if ($notification->tipo_recurso === 'artefacto_exportacion') {
            $artifact = ExportArtifact::query()->with('revision')->find($notification->recurso_id);

            return null;
        }
        if ($notification->tipo_recurso === 'ejecucion_ia') {
            $execution = AiExecution::query()->with(['syllabus', 'field'])->find($notification->recurso_id);

            return $execution !== null && $actor->can('edit', $execution->syllabus)
                ? "/mis-silabos/{$execution->syllabus->id}"
                : null;
        }

        return null;
    }
}
