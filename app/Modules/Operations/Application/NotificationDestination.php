<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\AiAssistance\Application\Queries\AiHistorySyllabusLookup;
use App\Modules\Documents\Application\Queries\ExportArtifactLookup;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Application\Queries\SyllabusNavigationLookup;
use Illuminate\Support\Collection;

/**
 * Decide a qué pantalla lleva un aviso y con cuál de los roles vigentes de la persona
 * (I-121). Lee los otros módulos solo mediante sus consultas públicas. Solo elige entre asignaciones que ella ya puede activar: no concede acceso,
 * y cada pantalla sigue aplicando sus propias políticas después del cambio de rol.
 *
 * @phpstan-type Destination array{assignment: RoleAssignment|null, path: string}
 */
final class NotificationDestination
{
    private const COORDINATION_EVENTS = ['biblioteca.verificado', 'silabo.firmado_docente', 'silabo.devuelto_decanato', 'coordinacion.asignada'];

    private const DEANERY_EVENTS = ['silabo.revisado_coordinacion', 'silabo.firmado_biblioteca'];

    /**
     * Avisos solo informativos (I-163): no piden hacer nada, así que no llevan enlace.
     * Los demás llevan a la pantalla donde se actúa (revisar, corregir, firmar, continuar).
     */
    public const INFORMATIONAL_EVENTS = [
        'usuario.activado',
        'proceso.plazo_extendido',
        'proceso.pausado',
        'proceso.cerrado',
        'convocatoria.pausada',
    ];

    private const LIBRARY_EVENTS = ['silabo.enviado', 'silabo.reenviado', 'silabo.firmado_coordinacion'];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly SyllabusNavigationLookup $syllabi,
        private readonly AiHistorySyllabusLookup $aiHistory,
        private readonly ExportArtifactLookup $artifacts,
    ) {}

    /**
     * @param  Collection<int, RoleAssignment>|null  $eligible  Asignaciones vigentes ya cargadas.
     * @return Destination|null
     */
    public function resolve(InternalNotification $notification, User $user, ?RoleAssignment $active, ?Collection $eligible = null): ?array
    {
        if (in_array($notification->tipo, self::INFORMATIONAL_EVENTS, true)) {
            return null;
        }
        if ($notification->tipo_recurso === 'asignacion_rol') {
            return ['assignment' => null, 'path' => '/rol'];
        }

        $options = $this->options($notification, $user, $eligible ?? $this->roles->eligible($user));
        foreach ($options as $option) {
            if ($active !== null && $option['assignment']?->id === $active->id) {
                return $option;
            }
        }

        return $options[0] ?? null;
    }

    public static function contextLabel(?RoleAssignment $assignment): ?string
    {
        if ($assignment === null) {
            return null;
        }
        $career = $assignment->career?->nombre;

        return match ($assignment->role->codigo_rol) {
            RoleCode::Coordinator->value => 'Coordinación'.($career !== null ? " · {$career}" : ''),
            RoleCode::Teacher->value => 'Docencia'.($career !== null ? " · {$career}" : ''),
            RoleCode::Librarian->value => 'Biblioteca',
            RoleCode::Dean->value => 'Decanato'.($assignment->faculty?->nombre !== null ? " · {$assignment->faculty->nombre}" : ''),
            default => 'Administración',
        };
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function options(InternalNotification $notification, User $user, Collection $eligible): array
    {
        $id = $notification->recurso_id;
        if ($id === null) {
            return [];
        }

        return match ($notification->tipo_recurso) {
            'silabo' => $this->syllabusOptions($id, $notification, $user, $eligible),
            'ejecucion_ia', 'revision_ia_silabo' => $this->aiOptions($id, $user, $eligible),
            'artefacto_exportacion' => $this->artifactOptions($id, $user, $eligible),
            'convocatoria' => $this->careerOptions(
                $this->syllabi->convocationCareerIds($id),
                $eligible,
                teacherFirst: true,
            ),
            'proceso_silabos' => $this->careerOptions(
                $this->syllabi->processCareerIds($id),
                $eligible,
                teacherFirst: true,
            ),
            'carrera' => $notification->tipo === 'coordinacion.asignada'
                ? $this->withRole($eligible, RoleCode::Coordinator, [$id], '/coordinacion/revisiones')
                : $this->withRole($eligible, RoleCode::Teacher, [$id], '/docente/mis-silabos'),
            'usuario' => [
                ...$this->withRole($eligible, RoleCode::Administrator, null, '/admin/usuarios?'.http_build_query(['q' => User::query()->whereKey($id)->value('correo_electronico') ?? ''])),
                ...$this->withRole($eligible, RoleCode::Coordinator, null, '/coordinacion/asignaciones-docentes'),
            ],
            default => [],
        };
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function syllabusOptions(string $syllabusId, InternalNotification $notification, User $user, Collection $eligible): array
    {
        if (! $this->syllabi->exists($syllabusId)) {
            return [];
        }
        $careerId = $this->syllabi->careerId($syllabusId);
        $revision = $this->syllabi->latestRevisionId($syllabusId);
        if (in_array($notification->tipo, self::LIBRARY_EVENTS, true)) {
            return $this->withRole($eligible, RoleCode::Librarian, null, $revision !== null ? "/biblioteca/revisiones/{$revision}" : '/biblioteca/revisiones');
        }
        if (in_array($notification->tipo, self::DEANERY_EVENTS, true)) {
            return $this->withRole($eligible, RoleCode::Dean, null, $revision !== null ? "/decanato/revisiones/{$revision}" : '/decanato/revisiones');
        }
        $teacher = $this->teacherOptions($syllabusId, $user, $eligible, "/docente/mis-silabos/{$syllabusId}");
        $coordinator = $this->withRole(
            $eligible,
            RoleCode::Coordinator,
            $careerId !== null ? [$careerId] : [],
            $revision !== null ? "/coordinacion/revisiones/{$revision}" : '/coordinacion/revisiones',
        );

        return in_array($notification->tipo, self::COORDINATION_EVENTS, true)
            ? [...$coordinator, ...$teacher]
            : [...$teacher, ...$coordinator];
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function aiOptions(string $entryId, User $user, Collection $eligible): array
    {
        $syllabusId = $this->aiHistory->syllabusId($entryId);

        return $syllabusId === null || ! $this->syllabi->exists($syllabusId)
            ? []
            : $this->teacherOptions($syllabusId, $user, $eligible, "/docente/mis-silabos/{$syllabusId}");
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function artifactOptions(string $artifactId, User $user, Collection $eligible): array
    {
        $target = $this->artifacts->revisionOf($artifactId);
        if ($target === null) {
            return [];
        }
        ['revision_id' => $revisionId, 'syllabus_id' => $syllabusId] = $target;
        $careerId = $this->syllabi->careerId($syllabusId);

        return [
            ...$this->teacherOptions($syllabusId, $user, $eligible, "/docente/revisiones/{$revisionId}/documentos"),
            ...$this->withRole(
                $eligible,
                RoleCode::Coordinator,
                $careerId !== null ? [$careerId] : [],
                "/coordinacion/revisiones/{$revisionId}/documentos",
            ),
        ];
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function teacherOptions(string $syllabusId, User $user, Collection $eligible, string $path): array
    {
        $assignmentIds = $this->syllabi->teacherAssignmentIds($syllabusId, $user->id);

        $options = [];
        foreach ($eligible as $assignment) {
            if (in_array($assignment->id, $assignmentIds, true)) {
                $options[] = ['assignment' => $assignment, 'path' => $path];
            }
        }

        return $options;
    }

    /**
     * @param  list<string>  $careers
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function careerOptions(array $careers, Collection $eligible, bool $teacherFirst): array
    {
        $teacher = $this->withRole($eligible, RoleCode::Teacher, $careers, '/docente/mis-silabos');
        $coordinator = $this->withRole($eligible, RoleCode::Coordinator, $careers, '/coordinacion/convocatorias');

        return $teacherFirst ? [...$teacher, ...$coordinator] : [...$coordinator, ...$teacher];
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @param  list<string>|null  $careerIds  `null` cuando el rol no depende de una carrera.
     * @return list<Destination>
     */
    private function withRole(Collection $eligible, RoleCode $role, ?array $careerIds, string $path): array
    {
        $options = [];
        foreach ($eligible as $assignment) {
            if ($assignment->role->codigo_rol === $role->value
                && ($careerIds === null || in_array($assignment->carrera_id, $careerIds, true))) {
                $options[] = ['assignment' => $assignment, 'path' => $path];
            }
        }

        return $options;
    }
}
