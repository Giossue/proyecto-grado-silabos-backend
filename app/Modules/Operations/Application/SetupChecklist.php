<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\CoordinatorAssignment;
use App\Modules\Academic\Infrastructure\Persistence\Models\Faculty;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Documents\Infrastructure\Persistence\Models\DocumentSignature;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\CoordinatorConvocationReadiness;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type Step array{key: string, label: string, hint: string, done: bool, action: string|null}
 * @phpstan-type Checklist array{title: string, intro: string, done: int, total: int, steps: list<Step>}
 *
 * @phpstan-import-type CoordinatorReadiness from CoordinatorConvocationReadiness
 */
class SetupChecklist
{
    public function __construct(private readonly CoordinatorConvocationReadiness $coordinatorReadiness) {}

    /** @return array{title: string, done: int, total: int}|null */
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
        $hasAccountWithRole = fn (RoleCode $role): bool => User::query()->where('activo', true)->whereHas('roleAssignments', fn (Builder $query) => $query
            ->where('activo', true)
            ->where('rol', $role->value))->exists();
        $accountsReady = $hasAccountWithRole(RoleCode::Coordinator) && $hasAccountWithRole(RoleCode::Librarian);
        $template = SyllabusTemplate::query()->first();
        $templateReady = $template?->hasStructure() ?? false;

        return $this->build(
            'Puesta en marcha de la institución',
            'En este orden. Cada paso habilita el siguiente; las coordinaciones no pueden empezar hasta que termine.',
            [
                $this->step('faculties', 'Registrar las facultades', 'Elija el campus de cada facultad. El logo puede cargarse ahora o después, pero Coordinación lo necesita para abrir su convocatoria.', Faculty::query()->where('activo', true)->exists()),
                $this->step('careers', 'Registrar las carreras', 'Cada carrera hereda el campus de su facultad y registra su modalidad aprobada. Puede importarlas desde Excel.', $careerCount > 0),
                $this->step('accounts', 'Crear las cuentas de coordinación y de biblioteca', 'Al menos una de cada una, también por importación desde Excel. Los docentes los crea cada Coordinación.', $accountsReady),
                $this->step('coordinators', 'Asignar un coordinador a cada carrera', $careerCount > 0 && $careersWithoutCoordinator > 0 ? "Faltan {$careersWithoutCoordinator} de {$careerCount} carreras." : 'Coordinación actualmente activa.', $careerCount > 0 && $careersWithoutCoordinator === 0),
                $this->step('template', 'Armar la plantilla del sílabo', 'Secciones y campos con un responsable: Docente, Coordinación o Institucional. El mismo editor permite texto, listas y tablas.', $templateReady),
                $this->step('process', 'Abrir la convocatoria institucional', 'Desde Convocatorias, indique las fechas del periodo lectivo y de elaboración y entrega; cada Coordinación abrirá después la de su carrera.', SyllabusProcess::query()->exists()),
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
                $this->step('curriculum', 'Armar la malla con sus materias', 'Ciclos, materias, horas, créditos y prerrequisitos. Se edita desde la vista de la malla.', $readiness['curriculum_ready']),
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
            $readiness['subjects_without_profile'] > 0 => "Complete la ficha de {$readiness['subjects_without_profile']} materia(s) en Proyecto de carrera.",
            $readiness['can_open'] => 'Todo está listo. Puede abrir la convocatoria.',
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
        $approvedIds = (clone $own)->where('estado', 'aprobado')->pluck('id')->all();
        $approved = count($approvedIds);
        $signed = $approvedIds === [] ? 0 : DocumentSignature::query()
            ->where('rol_firma', DocumentSignature::ROLE_TEACHER)
            ->whereHas('artifact.revision', fn (Builder $revision) => $revision->whereIn('silabo_id', $approvedIds))
            ->with('artifact.revision:id,silabo_id')
            ->get()
            ->map(fn (DocumentSignature $signature): string => $signature->artifact->revision->silabo_id)
            ->unique()
            ->count();

        return $this->build(
            'Sus sílabos, paso a paso',
            $total === 0 ? 'Cuando Coordinación abra la convocatoria, aquí aparecerán sus sílabos.' : 'Cada sílabo se abre, se completa, lo revisan Biblioteca y Coordinación y, una vez aprobado, se firma.',
            [
                $this->step('assigned', 'Recibir los sílabos asignados', $total > 0 ? "{$total} asignados." : 'Los asigna Coordinación al abrir la convocatoria.', $total > 0),
                $this->step('started', 'Abrir cada sílabo', $total > 0 ? "{$started} de {$total} abiertos." : 'Al abrirlo, el borrador parte de la ficha de la materia; también puede copiar el de otro de sus paralelos.', $total > 0 && $started === $total),
                $this->step('submitted', 'Completar y enviar a revisión', $total > 0 ? "{$submitted} de {$total} enviados." : 'Antes de la fecha de entrega. Primero lo revisa Coordinación y luego Biblioteca; el asistente de IA ayuda a redactar.', $total > 0 && $submitted === $total),
                $this->step('signed', 'Firmar electrónicamente el sílabo aprobado', $approved > 0 ? "{$signed} de {$approved} aprobados firmados." : 'Cuando Coordinación lo apruebe, fírmelo con su certificado (.p12); después firman Coordinación y Biblioteca.', $total > 0 && $approved === $total && $signed === $approved),
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
