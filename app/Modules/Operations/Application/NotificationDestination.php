<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\Documents\Application\Queries\ExportArtifactLookup;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Application\Queries\SyllabusNavigationLookup;
use Illuminate\Support\Collection;

/** @phpstan-type Destination array{assignment: RoleAssignment|null, path: string} */
final class NotificationDestination
{
    private const ADMINISTRATION_EVENTS = ['documento.exportacion_fallida'];

    private const LIBRARY_EVENTS = ['coordinacion.aprobado', 'silabo.reenviado_biblioteca'];

    /** Avisos a Coordinación y Biblioteca sobre todo el proceso: llevan a la bandeja de revisiones. */
    private const PROCESS_STAFF_EVENTS = ['proceso.reanudado'];

    private const PROCESS_TEACHER_EVENTS = ['proceso.plantilla_rehecho', 'proceso.plazo_extendido'];

    private const TEACHER_EVENTS = [
        'convocatoria.abierta',
        'convocatoria.reanudada',
        'silabo.correccion_solicitada',
        'silabo.devuelto_borrador',
        'silabo.eliminado',
        'silabo.regenerado',
        'silabo.aprobado',
    ];

    public function __construct(
        private readonly ActiveRole $roles,
        private readonly SyllabusNavigationLookup $syllabi,
        private readonly ExportArtifactLookup $artifacts,
    ) {}

    /**
     * @param  Collection<int, RoleAssignment>|null  $eligible
     * @return Destination|null
     */
    public function resolve(InternalNotification $notification, User $user, ?RoleAssignment $active, ?Collection $eligible = null): ?array
    {
        $eligible ??= $this->roles->eligible($user);
        if (in_array($notification->tipo, self::ADMINISTRATION_EVENTS, true)) {
            $path = '/admin/auditoria?'.http_build_query(['action' => $notification->tipo]);

            return $this->withRole($eligible, RoleCode::Administrator, null, $path)[0] ?? null;
        }
        if ($notification->tipo_recurso === 'proceso_silabos' && is_string($notification->recurso_id)) {
            $careers = $this->syllabi->processCareerIds($notification->recurso_id);
            if (in_array($notification->tipo, self::PROCESS_TEACHER_EVENTS, true)) {
                return $this->withRole($eligible, RoleCode::Teacher, $careers, '/docente/mis-silabos')[0] ?? null;
            }
            if (in_array($notification->tipo, self::PROCESS_STAFF_EVENTS, true)) {
                return $this->preferActive([
                    ...$this->withRole($eligible, RoleCode::Coordinator, $careers, '/coordinacion/revisiones'),
                    ...$this->withRole($eligible, RoleCode::Librarian, null, '/biblioteca/revisiones'),
                ], $active);
            }
        }

        $options = $this->options($notification, $user, $eligible);
        if (in_array($notification->tipo, self::TEACHER_EVENTS, true)) {
            $options = array_values(array_filter(
                $options,
                fn (array $option): bool => $option['assignment']?->role->codigo_rol === RoleCode::Teacher->value,
            ));
        }

        return $this->preferActive($options, $active);
    }

    /**
     * @param  list<Destination>  $options
     * @return Destination|null
     */
    private function preferActive(array $options, ?RoleAssignment $active): ?array
    {
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
            'carrera' => $this->withRole($eligible, RoleCode::Teacher, [$id], '/docente/mis-silabos'),
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
        $teacher = $this->teacherOptions($syllabusId, $user, $eligible, "/docente/mis-silabos/{$syllabusId}");
        $coordinator = $this->withRole(
            $eligible,
            RoleCode::Coordinator,
            $careerId !== null ? [$careerId] : [],
            $revision !== null ? "/coordinacion/revisiones/{$revision}" : '/coordinacion/revisiones',
        );

        return [...$teacher, ...$coordinator];
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
        $coordinator = $this->withRole($eligible, RoleCode::Coordinator, $careers, '/coordinacion/revisiones');

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
