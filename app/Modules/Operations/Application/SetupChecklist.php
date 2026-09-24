<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CoordinatorAssignment;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\CoordinatorConvocationReadiness;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Puesta en marcha» del panel: qué debe hacer cada rol, en orden, y qué le falta.
 * Cada paso se calcula con datos reales; no se marca a mano ni se guarda. Cuando
 * todo está hecho, el panel deja de mostrarlo.
 *
 * @phpstan-type Step array{key: string, label: string, hint: string, done: bool, action: string|null}
 * @phpstan-type Checklist array{title: string, intro: string, done: int, total: int, steps: list<Step>}
 *
 * @phpstan-import-type CoordinatorReadiness from CoordinatorConvocationReadiness
 */
class SetupChecklist
{
    public function __construct(private readonly CoordinatorConvocationReadiness $coordinatorReadiness) {}

    /**
     * Resumen para el encabezado: cuánto falta según el rol activo.
     *
     * @return array{title: string, done: int, total: int}|null
     */
    public function summaryFor(User $user, RoleAssignment $assignment): ?array
    {
        $checklist = match ($assignment->role->codigo_rol) {
            RoleCode::Administrator->value => $this->forAdministrator(),
            RoleCode::Coordinator->value => $this->forCoordinator($assignment->carrera_id),
            RoleCode::Teacher->value => $this->forTeacher($user, $assignment->carrera_id),
            default => null,
        };

        return $checklist === null
            ? null
            : ['title' => $checklist['title'], 'done' => $checklist['done'], 'total' => $checklist['total']];
    }

    /** @return Checklist */
    public function forAdministrator(): array
    {
        $careers = Career::query()->where('activo', true);
        $coordinated = CoordinatorAssignment::query()->where('activo', true)->select('carrera_id');
        $careersWithoutCoordinator = (clone $careers)->whereNotIn('id', $coordinated)->count();
        $careerCount = (clone $careers)->count();
        $accounts = User::query()->where('activo', true)->whereHas('roleAssignments', fn (Builder $query) => $query
            ->where('activo', true)
            ->where('rol', '!=', 'administrador'))->count();
        $template = SyllabusTemplate::query()->first();
        $templateReady = $template?->hasStructure() ?? false;

        return $this->build(
            'Puesta en marcha de la institución',
            'En este orden. Cada paso habilita el siguiente; las coordinaciones no pueden empezar hasta que termine.',
            [
                $this->step('faculties', 'Registrar las facultades', 'Elija el campus y, cuando corresponda, cargue el logo de cada facultad.', Faculty::query()->where('activo', true)->exists()),
                $this->step('careers', 'Registrar las carreras', 'Cada carrera hereda el campus de su facultad y registra su modalidad aprobada.', $careerCount > 0),
                $this->step('periods', 'Registrar el periodo académico', 'El periodo que abrirá el primer proceso de sílabos.', AcademicPeriod::query()->exists()),
                $this->step('accounts', 'Crear las cuentas de coordinadores y docentes', 'Con su rol; el docente se asigna a paralelos desde Coordinación.', $accounts > 0),
                $this->step('coordinators', 'Asignar un coordinador a cada carrera', $careerCount > 0 && $careersWithoutCoordinator > 0 ? "Faltan {$careersWithoutCoordinator} de {$careerCount} carreras." : 'Coordinación actualmente activa.', $careerCount > 0 && $careersWithoutCoordinator === 0),
                $this->step('template', 'Crear y formatear la plantilla de sílabo', 'Cree el contenedor vacío y aplique el formato oficial o configure sus bloques manualmente.', $templateReady),
                $this->step('process', 'Preparar y abrir la convocatoria', 'Desde Periodos académicos elija el período y confirme las fechas de elaboración; las coordinaciones podrán iniciar sus alcances.', SyllabusProcess::query()->exists()),
            ],
        );
    }

    /**
     * @param  CoordinatorReadiness|null  $readiness
     * @return Checklist
     */
    public function forCoordinator(?string $careerId, ?array $readiness = null): array
    {
        if ($careerId === null) {
            return $this->build('Puesta en marcha de la carrera', '', []);
        }

        $readiness ??= $this->coordinatorReadiness->inspect($careerId);
        $process = $readiness['process'];
        $period = $process?->academicPeriod?->codigo;
        $scheduledTotal = $readiness['scheduled_total'];
        $scheduledWithParallels = $readiness['scheduled_with_parallels'];
        $parallelTotal = $readiness['parallel_total'];
        $parallelsWithTeacher = $readiness['parallels_with_teacher'];

        return $this->build(
            'Puesta en marcha de la carrera',
            'En este orden. Al abrir la convocatoria se crea un sílabo por paralelo con su docente.',
            [
                $this->step('curriculum', 'Armar la malla con sus materias', 'Ciclos, materias, horas, créditos y prerrequisitos.', $readiness['curriculum_ready']),
                $this->step(
                    'scheduled_subjects',
                    'Programar las materias del período',
                    $period === null ? 'Espera a que Administración abra la convocatoria institucional para conocer el período.' : "Registre las materias que se impartirán en {$period}.",
                    $scheduledTotal > 0,
                ),
                $this->step(
                    'parallels',
                    'Crear los paralelos',
                    $scheduledTotal === 0 ? 'Primero programe las materias del período.' : "{$scheduledWithParallels} de {$scheduledTotal} materias programadas tienen paralelos.",
                    $scheduledTotal > 0 && $scheduledWithParallels === $scheduledTotal,
                ),
                $this->step(
                    'teachers',
                    'Asignar un docente a cada paralelo',
                    $parallelTotal === 0 ? 'Primero cree los paralelos del período.' : "{$parallelsWithTeacher} de {$parallelTotal} paralelos tienen un docente vigente.",
                    $parallelTotal > 0 && $parallelsWithTeacher === $parallelTotal,
                ),
                $this->step('sources', 'Registrar al menos una fuente académica', 'Documento de apoyo que la convocatoria fija para los docentes.', $readiness['sources_total'] > 0),
                $this->step(
                    'convocation',
                    'Abrir la convocatoria de la carrera',
                    $this->coordinatorConvocationHint($readiness),
                    $readiness['convocation'] !== null,
                    $readiness['can_open'] ? 'open_convocation' : null,
                ),
            ],
        );
    }

    /** @param CoordinatorReadiness $readiness */
    private function coordinatorConvocationHint(array $readiness): string
    {
        $process = $readiness['process'];

        return match (true) {
            $process === null => 'Espera a que Administración abra la convocatoria institucional.',
            $process->estado === SyllabusProcess::STATE_PAUSED => 'La convocatoria institucional está en pausa.',
            ! $readiness['deadline_open'] => 'El plazo institucional venció. Administración debe prorrogarlo antes de abrir la convocatoria.',
            ! $readiness['template_ready'] => 'Administración debe completar la plantilla institucional.',
            ! $readiness['faculty_logo_ready'] => 'Administración debe cargar el logo de la facultad.',
            $readiness['can_open'] => 'Todo está listo. Seleccione las fuentes y abra la convocatoria.',
            default => 'Complete los pasos anteriores para poder abrirla.',
        };
    }

    /** @return Checklist */
    public function forTeacher(User $user, ?string $careerId): array
    {
        $own = Syllabus::query()
            ->when($careerId !== null, fn (Builder $query) => $query->whereHas('convocation', fn (Builder $convocation) => $convocation->where('carrera_id', $careerId)))
            ->whereCurrentTeacher($user);
        $total = (clone $own)->count();
        $started = (clone $own)->where('estado', '!=', 'sin_iniciar')->count();
        $submitted = (clone $own)->whereIn('estado', ['en_revision', 'aprobado'])->count();

        return $this->build(
            'Sus sílabos, paso a paso',
            $total === 0 ? 'Cuando Coordinación abra la convocatoria, aquí aparecerán sus sílabos.' : 'Cada sílabo se inicia, se completa y se envía a revisión.',
            [
                $this->step('assigned', 'Recibir los sílabos asignados', $total > 0 ? "{$total} asignados." : 'Los asigna Coordinación al abrir la convocatoria.', $total > 0),
                $this->step('started', 'Iniciar cada sílabo', $total > 0 ? "{$started} de {$total} iniciados." : 'Al iniciar se copian los datos de la malla.', $total > 0 && $started === $total),
                $this->step('submitted', 'Completar y enviar a revisión', $total > 0 ? "{$submitted} de {$total} enviados." : 'Antes de la fecha de entrega.', $total > 0 && $submitted === $total),
            ],
        );
    }

    /**
     * @param  list<Step>  $steps
     * @return Checklist
     */
    private function build(string $title, string $intro, array $steps): array
    {
        $done = count(array_filter($steps, fn (array $step): bool => $step['done']));

        return ['title' => $title, 'intro' => $intro, 'done' => $done, 'total' => count($steps), 'steps' => $steps];
    }

    /** @return Step */
    private function step(
        string $key,
        string $label,
        string $hint,
        bool $done,
        ?string $action = null,
    ): array {
        return ['key' => $key, 'label' => $label, 'hint' => $hint, 'done' => $done, 'action' => $action];
    }
}
