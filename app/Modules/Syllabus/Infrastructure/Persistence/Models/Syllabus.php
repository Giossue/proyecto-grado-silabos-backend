<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * @property string $id
 * @property string $convocatoria_id
 * @property string $estado
 * @property int $version_bloqueo
 * @property int $unresolved_observations_count
 * @property array<string, mixed> $contexto_academico
 * @property CarbonImmutable|null $iniciado_en
 * @property CarbonImmutable|null $guardado_en
 * @property-read Convocation $convocation
 * @property-read Subject $subject Materia del paralelo (derivada, I-164).
 * @property-read ScheduledSubject $scheduledSubject
 * @property-read Career $career
 * @property-read Parallel $parallel
 * @property string $paralelo_id
 */
class Syllabus extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'estado' => 'estado_silabo',
        'convocatoria_id' => 'convocatoria_carrera_id',
        'contexto_academico' => 'contexto_academico_silabo',
    ];

    public $timestamps = false;

    protected $table = 'silabos';

    /** @var list<string> */
    protected $fillable = [
        'convocatoria_id', 'paralelo_id', 'estado',
        'contexto_academico', 'version_bloqueo', 'iniciado_en', 'guardado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version_bloqueo' => 'integer',
            'contexto_academico' => 'array',
            'iniciado_en' => 'immutable_datetime',
            'guardado_en' => 'immutable_datetime',
        ];
    }

    public function academicSubjectName(): string
    {
        $name = data_get($this->contexto_academico, 'subject.name');

        return is_string($name) ? $name : $this->subject->nombre;
    }

    public function academicSubjectCode(): string
    {
        $code = data_get($this->contexto_academico, 'subject.code');

        return is_string($code) ? $code : $this->subject->codigo_asignatura;
    }

    /** @return BelongsTo<Convocation, $this> */
    public function convocation(): BelongsTo
    {
        return $this->belongsTo(Convocation::class, 'convocatoria_carrera_id');
    }

    /** @return HasOneThrough<ScheduledSubject, Parallel, $this> Programación a la que pertenece el paralelo. */
    public function scheduledSubject(): HasOneThrough
    {
        return $this->hasOneThrough(ScheduledSubject::class, Parallel::class, 'id', 'id', 'paralelo_id', 'asignatura_ofertada_id');
    }

    // La materia sale del paralelo (I-164): ya no se guarda en el sílabo.
    public function getSubjectAttribute(): Subject
    {
        $this->loadMissing('parallel.scheduledSubject.subject');

        return $this->parallel->scheduledSubject->subject;
    }

    /**
     * Sílabos de estas materias, a través de su paralelo.
     *
     * @param  Builder<Syllabus>  $query
     * @param  list<string>  $subjectIds
     * @return Builder<Syllabus>
     */
    public function scopeForSubjects(Builder $query, array $subjectIds): Builder
    {
        return $query->whereHas('parallel.scheduledSubject', fn (Builder $scheduled) => $scheduled->whereIn('asignatura_id', $subjectIds));
    }

    /** @return HasOneThrough<Career, Convocation, $this> */
    public function career(): HasOneThrough
    {
        return $this->hasOneThrough(Career::class, Convocation::class, 'id', 'id', 'convocatoria_carrera_id', 'carrera_id');
    }

    /** @return BelongsTo<Parallel, $this> */
    public function parallel(): BelongsTo
    {
        return $this->belongsTo(Parallel::class, 'paralelo_id');
    }

    /** @return HasMany<CurrentSyllabusTeacher, $this> */
    public function currentTeachers(): HasMany
    {
        return $this->hasMany(CurrentSyllabusTeacher::class, 'silabo_id');
    }

    /** @return BelongsToMany<User, $this> Usuarios docentes vigentes (ver `currentTeachers`). */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'docentes_vigentes_silabo', 'silabo_id', 'usuario_id');
    }

    /** @param  Builder<Syllabus>  $query */
    public function scopeWhereCurrentTeacher(Builder $query, User|string $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $query->whereHas('currentTeachers', fn (Builder $teacher): Builder => $teacher
            ->where('usuario_id', $userId)
            ->whereHas('user', fn (Builder $account): Builder => $account->where('activo', true)));
    }

    /** @return HasMany<FieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(FieldValue::class, 'silabo_id');
    }

    /** @return HasMany<ValidationRun, $this> */
    public function validationRuns(): HasMany
    {
        return $this->hasMany(ValidationRun::class, 'silabo_id');
    }

    /** @return HasMany<SyllabusRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(SyllabusRevision::class, 'silabo_id');
    }

    /** @return HasManyThrough<ReviewObservation, SyllabusRevision, $this> */
    public function reviewObservations(): HasManyThrough
    {
        return $this->hasManyThrough(
            ReviewObservation::class,
            SyllabusRevision::class,
            'silabo_id',
            'revision_silabo_id',
        );
    }

    /** @return HasManyThrough<Approval, SyllabusRevision, $this> */
    public function approvals(): HasManyThrough
    {
        return $this->hasManyThrough(Approval::class, SyllabusRevision::class, 'silabo_id', 'revision_silabo_id');
    }

    /** @return HasManyThrough<Reopening, SyllabusRevision, $this> */
    public function reopenings(): HasManyThrough
    {
        return $this->hasManyThrough(Reopening::class, SyllabusRevision::class, 'silabo_id', 'revision_aprobada_id');
    }
}
