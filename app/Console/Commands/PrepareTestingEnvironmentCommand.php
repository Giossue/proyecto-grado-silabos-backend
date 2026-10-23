<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Academic\Application\AcademicPeriodPlanning;
use App\Modules\Academic\Application\Actions\CreateAcademicRecord;
use App\Modules\Academic\Application\Actions\CreateParallels;
use App\Modules\Academic\Application\Actions\ReplaceCoordinator;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Application\TemplateStructureValidator;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Modules\Identity\Application\Actions\ActivateManagedUser;
use App\Modules\Identity\Application\Actions\CreateCareerTeacher;
use App\Modules\Identity\Application\Actions\CreateManagedUser;
use App\Modules\Identity\Application\Actions\IssueActivationInvitation;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Infrastructure\Mail\ManagedUserActivationMail;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Syllabus\Application\Actions\CreateConvocation;
use App\Modules\Syllabus\Application\Actions\CreateSyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PrepareTestingEnvironmentCommand extends Command
{
    public const COORDINATOR_EMAIL = 'coordinador.pruebas@ueb.edu.ec';

    /** @var list<array{email: string, name: string}> */
    public const TEACHERS = [
        ['email' => 'docente1.pruebas@ueb.edu.ec', 'name' => 'Docente Uno Pruebas'],
        ['email' => 'docente2.pruebas@ueb.edu.ec', 'name' => 'Docente Dos Pruebas'],
    ];

    public const PARALLEL_CODE = 'A';

    protected $signature = 'pruebas:preparar
        {--carrera=SW87SA : Código de la carrera}
        {--dias=30 : Días hasta la entrega si hay que crear la convocatoria institucional}';

    protected $description = 'Prepara coordinador, dos docentes, paralelos y convocatoria para pruebas (lee PRUEBAS_CONTRASENA)';

    public function handle(): int
    {
        $password = getenv('PRUEBAS_CONTRASENA');
        $password = is_string($password) ? $password : '';
        $check = Validator::make(['password' => $password], [
            'password' => ['required', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ]);
        if ($check->fails()) {
            $this->components->error('Defina PRUEBAS_CONTRASENA (12+ caracteres, mayúsculas, minúsculas, números y símbolos).');

            return self::FAILURE;
        }

        try {
            $this->run_($password);
        } catch (ValidationException $exception) {
            $this->components->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function run_(string $password): void
    {
        $option = $this->option('carrera');
        $code = is_string($option) ? $option : '';
        $career = Career::query()->where('codigo_carrera', $code)->first()
            ?? throw new RuntimeException("No existe la carrera {$code}.");

        $this->inspect($career);

        Mail::fake();

        $admin = RoleAssignment::query()->effective()->where('rol', RoleCode::Administrator->value)
            ->whereHas('user', fn ($query) => $query->where('activo', true)->whereNotNull('correo_verificado_en'))
            ->orderBy('asignado_en')->first()
            ?? throw new RuntimeException('No hay una cuenta de Administración activa.');

        $coordinator = $this->ensureCoordinator($career, $admin, $password);
        $teachers = [];
        foreach (self::TEACHERS as $teacher) {
            $teachers[] = $this->ensureTeacher($teacher, $coordinator, $password);
        }

        $period = $this->currentPeriod();
        $this->ensureParallels($career, $period, $coordinator, $teachers);
        $this->ensureConvocation($career, $period, $admin, $coordinator);

        $this->verify($career);
    }

    private function inspect(Career $career): void
    {
        $this->components->info("Inspección previa de {$career->codigo_carrera}");
        $this->table(['Correo', 'Rol', 'Carrera', 'Activa', 'Verificado'], RoleAssignment::query()
            ->with(['user', 'career'])->get()
            ->map(fn (RoleAssignment $assignment): array => [
                $assignment->user->correo_electronico, $assignment->rol,
                $assignment->career === null ? '-' : $assignment->career->codigo_carrera,
                $assignment->activo ? 'sí' : 'no', $assignment->user->hasVerifiedEmail() ? 'sí' : 'no',
            ])->all());
        $this->table(['Período', 'Inicio', 'Fin', 'Estado'], AcademicPeriod::query()->orderBy('fecha_inicio')->get()
            ->map(fn (AcademicPeriod $period): array => [
                $period->codigo, $period->fecha_inicio->toDateString(), $period->fecha_fin->toDateString(),
                app(AcademicPeriodPlanning::class)->label($period),
            ])->all());
        $this->line(sprintf(
            'Malla: %d materias activas · paralelos: %d',
            Subject::query()->where('carrera_id', $career->id)->where('activo', true)->count(),
            Parallel::query()->whereHas('subject', fn ($q) => $q->where('carrera_id', $career->id))->count(),
        ));
        $this->line(sprintf(
            'Convocatorias institucionales: %s · de carrera: %s',
            SyllabusProcess::query()->pluck('estado')->implode(', ') ?: 'ninguna',
            Convocation::query()->where('carrera_id', $career->id)->pluck('estado')->implode(', ') ?: 'ninguna',
        ));

        $template = SyllabusTemplate::query()->first();
        if ($template === null || ! $template->hasStructure()) {
            throw new RuntimeException('La plantilla institucional está vacía.');
        }
        app(TemplateStructureValidator::class)->assertUsable($template);
        $this->line('Plantilla institucional: válida para abrir convocatorias.');
    }

    private function ensureCoordinator(Career $career, RoleAssignment $admin, string $password): RoleAssignment
    {
        $current = $this->coordinatorOf($career);
        if ($current !== null) {
            $this->line("Coordinación vigente reutilizada: {$current->user->correo_electronico}");

            return $current;
        }

        $request = $this->requestAs($admin);
        $user = User::query()->where('correo_electronico', self::COORDINATOR_EMAIL)->first()
            ?? app(CreateManagedUser::class)->execute([
                'nombre' => 'Coordinador Pruebas',
                'correo_electronico' => self::COORDINATOR_EMAIL,
                'role_code' => RoleCode::Coordinator->value,
                'career_id' => $career->id,
            ], $admin->user, $request);
        $this->activate($user, $admin, $password);
        if ($this->coordinatorOf($career) === null) {
            app(ReplaceCoordinator::class)->execute($career, ['incoming_user_id' => $user->id], $admin->user, $this->requestAs($admin));
        }
        $this->line('Coordinación creada: '.self::COORDINATOR_EMAIL);

        return $this->coordinatorOf($career) ?? throw new RuntimeException('No se pudo asignar la coordinación.');
    }

    private function coordinatorOf(Career $career): ?RoleAssignment
    {
        return RoleAssignment::query()->effective()->with('user')
            ->where('rol', RoleCode::Coordinator->value)->where('carrera_id', $career->id)
            ->whereHas('user', fn ($query) => $query->where('activo', true)->whereNotNull('correo_verificado_en'))
            ->first();
    }

    /** @param array{email: string, name: string} $data */
    private function ensureTeacher(array $data, RoleAssignment $coordinator, string $password): User
    {
        $user = User::query()->where('correo_electronico', $data['email'])->first();
        $hasRole = $user !== null && RoleAssignment::query()->effective()->where('usuario_id', $user->id)
            ->where('rol', RoleCode::Teacher->value)->where('carrera_id', $coordinator->carrera_id)->exists();
        if (! $hasRole) {
            app(CreateCareerTeacher::class)->execute(
                ['nombre' => $data['name'], 'correo_electronico' => $data['email']],
                $coordinator->user,
                $this->requestAs($coordinator),
            );
            $user = User::query()->where('correo_electronico', $data['email'])->firstOrFail();
        }
        $this->activate($user, $coordinator, $password);
        $this->line("Docente listo: {$data['email']}");

        return $user->fresh();
    }

    private function activate(User $user, RoleAssignment $issuer, string $password): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }
        app(IssueActivationInvitation::class)->execute($user, $issuer->user, $this->requestAs($issuer), 'pruebas');
        $mail = Mail::queued(ManagedUserActivationMail::class, fn ($mail): bool => $mail->hasTo($user->correo_electronico))->last()
            ?? throw new RuntimeException("No se generó la invitación de {$user->correo_electronico}.");
        parse_str((string) parse_url($mail->activationUrl, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        if (! is_string($token)) {
            throw new RuntimeException("La invitación de {$user->correo_electronico} no trae un enlace válido.");
        }
        app(ActivateManagedUser::class)->execute($token, $password, $this->freshRequest());
    }

    private function currentPeriod(): AcademicPeriod
    {
        $planning = app(AcademicPeriodPlanning::class);
        $process = SyllabusProcess::query()->inProgress()->with('academicPeriod')->first();
        if ($process !== null) {
            return $process->academicPeriod;
        }

        return AcademicPeriod::query()->orderByDesc('fecha_inicio')->get()
            ->first(fn (AcademicPeriod $period): bool => $planning->mayPlan($period))
            ?? throw new RuntimeException('No hay un período académico vigente o próximo.');
    }

    /** @param list<User> $teachers */
    private function ensureParallels(Career $career, AcademicPeriod $period, RoleAssignment $coordinator, array $teachers): void
    {
        $subjects = $this->pickSubjects($career, $teachers);
        $running = Convocation::query()->where('carrera_id', $career->id)->where('proceso_id', SyllabusProcess::query()
            ->where('periodo_academico_id', $period->id)->value('id'))->exists();

        foreach ($teachers as $index => $teacher) {
            $subject = $subjects[$index];
            if ($this->teacherParallel($teacher, $subject) !== null) {
                continue;
            }
            if ($running) {
                throw new RuntimeException('La convocatoria de la carrera ya existe: asigne el paralelo desde Coordinación.');
            }
            Parallel::query()->where('asignatura_id', $subject->id)->exists()
                ? $this->assignExisting($subject, $coordinator, $teacher)
                : $this->scheduleSubject($coordinator, $subject, $teacher);
            $this->line("Paralelo {$subject->nombre} asignado a {$teacher->correo_electronico}");
        }
    }

    /**
     * @param  list<User>  $teachers
     * @return list<Subject>
     */
    private function pickSubjects(Career $career, array $teachers): array
    {
        $active = Subject::query()->where('carrera_id', $career->id)->where('activo', true)
            ->orderBy('ciclo')->orderBy('orden_en_ciclo')->get();
        $picked = [];
        foreach ($teachers as $teacher) {
            $current = $active->first(fn (Subject $subject): bool => ! in_array($subject->id, array_map(fn ($s) => $s->id, $picked), true)
                && $this->teacherParallel($teacher, $subject) !== null);
            $picked[] = $current ?? $active->first(fn (Subject $subject): bool => ! in_array($subject->id, array_map(fn ($s) => $s->id, $picked), true)
                && ($picked !== [] || Str::contains(Str::lower($subject->nombre), 'calidad de software')))
                ?? $active->first(fn (Subject $subject): bool => ! in_array($subject->id, array_map(fn ($s) => $s->id, $picked), true))
                ?? throw new RuntimeException('La malla no tiene materias activas suficientes.');
        }

        return $picked;
    }

    private function teacherParallel(User $teacher, Subject $subject): ?TeacherAssignment
    {
        return TeacherAssignment::query()->where('activo', true)
            ->whereHas('roleAssignment', fn ($q) => $q->where('usuario_id', $teacher->id))
            ->whereHas('parallel', fn ($q) => $q->where('asignatura_id', $subject->id))
            ->first();
    }

    private function scheduleSubject(RoleAssignment $coordinator, Subject $subject, User $teacher): void
    {
        app(CreateParallels::class)->execute(['subject_id' => $subject->id, 'codes' => [self::PARALLEL_CODE], 'shift' => 'matutina', 'user_id' => $teacher->id], $coordinator->user, $this->requestAs($coordinator));
    }

    private function assignExisting(Subject $subject, RoleAssignment $coordinator, User $teacher): void
    {
        $parallels = fn () => Parallel::query()->where('asignatura_id', $subject->id);
        $free = $parallels()->whereDoesntHave('teacherAssignments', fn ($q) => $q->where('activo', true))->orderBy('codigo')->first();
        if ($free === null) {
            $code = 'P'.($parallels()->count() + 1);
            app(CreateParallels::class)->execute(['subject_id' => $subject->id, 'codes' => [$code], 'shift' => 'matutina', 'user_id' => $teacher->id], $coordinator->user, $this->requestAs($coordinator));

            return;
        }
        app(CreateAcademicRecord::class)->execute('asignacion_docente', ['parallel_id' => $free->id, 'user_id' => $teacher->id], $coordinator->user, $this->requestAs($coordinator));
    }

    private function ensureConvocation(Career $career, AcademicPeriod $period, RoleAssignment $admin, RoleAssignment $coordinator): void
    {
        $process = SyllabusProcess::query()->inProgress()->first();
        if ($process === null) {
            $timezone = (string) config('app.display_timezone', 'America/Guayaquil');
            $process = app(CreateSyllabusProcess::class)->execute([
                'period_id' => $period->id,
                'starts_at' => CarbonImmutable::today($timezone)->toIso8601String(),
                'due_at' => CarbonImmutable::today($timezone)->addDays((int) $this->option('dias'))->endOfDay()->toIso8601String(),
            ], $admin->user, $this->requestAs($admin));
            $this->line('Convocatoria institucional creada y abierta.');
        }
        if ($process->estado !== SyllabusProcess::STATE_OPEN) {
            throw new RuntimeException("La convocatoria institucional está en estado {$process->estado}; Administración debe reanudarla.");
        }
        if (Convocation::query()->where('carrera_id', $career->id)->where('proceso_id', $process->id)->exists()) {
            $this->line('La convocatoria de la carrera ya existe; se reutiliza.');

            return;
        }

        app(CreateConvocation::class)->execute(
            ['process_id' => $process->id],
            $coordinator->user,
            $this->requestAs($coordinator),
        );
        $this->line('Convocatoria de carrera abierta.');
    }

    private function verify(Career $career): void
    {
        $this->components->info('Verificación final');
        $emails = [self::COORDINATOR_EMAIL, ...array_column(self::TEACHERS, 'email')];
        $coordinator = $this->coordinatorOf($career);
        $this->table(['Correo', 'Rol', 'Carrera', 'Activa', 'Verificado', 'Paralelos'], RoleAssignment::query()->effective()
            ->with(['user', 'career'])->where('carrera_id', $career->id)
            ->where(fn ($q) => $q->whereHas('user', fn ($u) => $u->whereIn('correo_electronico', $emails))
                ->orWhere('id', $coordinator?->id))
            ->get()
            ->map(fn (RoleAssignment $assignment): array => [
                $assignment->user->correo_electronico, $assignment->rol, $assignment->career?->codigo_carrera,
                $assignment->user->activo ? 'sí' : 'no', $assignment->user->hasVerifiedEmail() ? 'sí' : 'no',
                TeacherAssignment::query()->where('asignacion_rol_id', $assignment->id)->where('activo', true)
                    ->with('parallel.subject')->get()
                    ->map(fn (TeacherAssignment $t): string => "{$t->parallel->subject->nombre} ({$t->parallel->codigo})")
                    ->implode(', ') ?: '-',
            ])->all());

        $this->table(['Institucional', 'Inicia', 'Entrega', 'Carrera'], SyllabusProcess::query()->inProgress()->get()
            ->map(fn (SyllabusProcess $process): array => [
                $process->estado, $process->inicia_en->toDateTimeString(), $process->entrega_en->toDateTimeString(),
                Convocation::query()->where('proceso_id', $process->id)->where('carrera_id', $career->id)->value('estado') ?? 'sin crear',
            ])->all());

        $this->table(['Docente', 'Materia', 'Paralelo', 'Estado sílabo'], Syllabus::query()
            ->whereHas('convocation', fn ($q) => $q->where('carrera_id', $career->id))
            ->with(['parallel.subject', 'parallel.teacherAssignments' => fn ($q) => $q->where('activo', true)->with('roleAssignment.user')])
            ->get()
            ->map(fn (Syllabus $syllabus): array => [
                $syllabus->parallel->teacherAssignments->first()?->roleAssignment->user->correo_electronico ?? '-',
                $syllabus->subject->nombre, $syllabus->parallel->codigo, $syllabus->estado,
            ])->all());
    }

    private function freshRequest(): Request
    {
        $request = Request::create('/pruebas', 'POST');
        $request->setLaravelSession(new Store('pruebas', new ArraySessionHandler(120)));
        $request->attributes->set('correlation_id', (string) Str::uuid());
        app()->instance('request', $request);

        return $request;
    }

    private function requestAs(RoleAssignment $assignment): Request
    {
        $request = $this->freshRequest();
        $user = $assignment->user;
        $request->setUserResolver(fn () => $user);
        $request->session()->put('active_role_assignment_id', $assignment->id);

        return $request;
    }
}
