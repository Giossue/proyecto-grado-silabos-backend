<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiExecution;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\InternalNotification;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusCollaborator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Decide a qué pantalla lleva un aviso y con cuál de los roles vigentes de la persona
 * (I-121). Solo elige entre asignaciones que ella ya puede activar: no concede acceso,
 * y cada pantalla sigue aplicando sus propias políticas después del cambio de rol.
 *
 * @phpstan-type Destination array{assignment: RoleAssignment|null, path: string}
 */
final class NotificationDestination
{
    /** Avisos dirigidos a Coordinación aunque la persona también enseñe esa materia. */
    private const COORDINATION_EVENTS = ['silabo.enviado', 'silabo.reenviado', 'coordinacion.asignada'];

    public function __construct(private readonly ActiveRole $roles) {}

    /**
     * @param  Collection<int, RoleAssignment>|null  $eligible  Asignaciones vigentes ya cargadas.
     * @return Destination|null
     */
    public function resolve(InternalNotification $notification, User $user, ?RoleAssignment $active, ?Collection $eligible = null): ?array
    {
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

    /** Nombre del ámbito en que se abrirá el aviso, para distinguir roles en la lista. */
    public static function contextLabel(?RoleAssignment $assignment): ?string
    {
        if ($assignment === null) {
            return null;
        }
        $career = $assignment->career?->nombre;

        return match ($assignment->role->codigo_rol) {
            RoleCode::Coordinator->value => 'Coordinación'.($career !== null ? " · {$career}" : ''),
            RoleCode::Teacher->value => 'Docencia'.($career !== null ? " · {$career}" : ''),
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
            'silabo' => $this->syllabusOptions(Syllabus::query()->find($id), $notification, $user, $eligible),
            'ejecucion_ia' => $this->aiOptions(AiExecution::query()->with('syllabus')->find($id), $user, $eligible),
            'artefacto_exportacion' => $this->artifactOptions(ExportArtifact::query()->with('revision.syllabus')->find($id), $user, $eligible),
            'convocatoria' => $this->careerOptions(
                Convocation::query()->whereKey($id)->pluck('carrera_id')->all(),
                $eligible,
                teacherFirst: true,
            ),
            'proceso_silabos' => $this->careerOptions(
                Convocation::query()->where('proceso_id', $id)->pluck('carrera_id')->all(),
                $eligible,
                teacherFirst: true,
            ),
            'carrera' => $notification->tipo === 'coordinacion.asignada'
                ? $this->withRole($eligible, RoleCode::Coordinator, [$id], '/coordinacion/revisiones')
                : $this->withRole($eligible, RoleCode::Teacher, [$id], '/docente/mis-silabos'),
            'usuario' => [
                ...$this->withRole($eligible, RoleCode::Administrator, null, "/admin/usuarios/{$id}"),
                ...$this->withRole($eligible, RoleCode::Coordinator, null, '/coordinacion/asignaciones-docentes'),
            ],
            default => [],
        };
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function syllabusOptions(?Syllabus $syllabus, InternalNotification $notification, User $user, Collection $eligible): array
    {
        if ($syllabus === null) {
            return [];
        }
        $careerId = $syllabus->convocation()->value('carrera_id');
        $revision = $syllabus->revisions()->latest('numero_revision')->value('id');
        $teacher = $this->teacherOptions($syllabus, $user, $eligible, "/docente/mis-silabos/{$syllabus->id}");
        $coordinator = $this->withRole(
            $eligible,
            RoleCode::Coordinator,
            is_string($careerId) ? [$careerId] : [],
            is_string($revision) ? "/coordinacion/revisiones/{$revision}" : '/coordinacion/revisiones',
        );

        return in_array($notification->tipo, self::COORDINATION_EVENTS, true)
            ? [...$coordinator, ...$teacher]
            : [...$teacher, ...$coordinator];
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function aiOptions(?AiExecution $execution, User $user, Collection $eligible): array
    {
        $syllabus = $execution?->syllabus;

        return $syllabus === null
            ? []
            : $this->teacherOptions($syllabus, $user, $eligible, "/docente/mis-silabos/{$syllabus->id}");
    }

    /**
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function artifactOptions(?ExportArtifact $artifact, User $user, Collection $eligible): array
    {
        $revision = $artifact?->revision;
        $syllabus = $revision?->syllabus;
        if ($revision === null || $syllabus === null) {
            return [];
        }
        $careerId = $syllabus->convocation()->value('carrera_id');

        return [
            ...$this->teacherOptions($syllabus, $user, $eligible, "/docente/revisiones/{$revision->id}/documentos"),
            ...$this->withRole(
                $eligible,
                RoleCode::Coordinator,
                is_string($careerId) ? [$careerId] : [],
                "/coordinacion/revisiones/{$revision->id}/documentos",
            ),
        ];
    }

    /**
     * La docencia de un sílabo depende de su asignación de paralelo vigente, no solo de
     * tener un rol docente en la carrera.
     *
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function teacherOptions(Syllabus $syllabus, User $user, Collection $eligible, string $path): array
    {
        $assignmentIds = SyllabusCollaborator::query()
            ->where('silabo_id', $syllabus->id)
            ->where('usuario_id', $user->id)
            ->whereHas('teacherAssignment', fn (Builder $query) => $query->where('activo', true))
            ->with('teacherAssignment:id,asignacion_rol_id')
            ->get()
            ->map(fn (SyllabusCollaborator $collaborator): ?string => $collaborator->teacherAssignment->asignacion_rol_id)
            ->filter()
            ->all();

        $options = [];
        foreach ($eligible as $assignment) {
            if (in_array($assignment->id, $assignmentIds, true)) {
                $options[] = ['assignment' => $assignment, 'path' => $path];
            }
        }

        return $options;
    }

    /**
     * @param  array<mixed>  $careerIds
     * @param  Collection<int, RoleAssignment>  $eligible
     * @return list<Destination>
     */
    private function careerOptions(array $careerIds, Collection $eligible, bool $teacherFirst): array
    {
        $careers = array_values(array_filter($careerIds, 'is_string'));
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
