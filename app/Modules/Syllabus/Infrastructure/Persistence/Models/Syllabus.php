<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\AiAssistance\Infrastructure\Persistence\Models\AiExecution;
use App\Modules\Configuration\Infrastructure\Persistence\Models\SyllabusTemplate;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
 * @property-read Subject $subject
 * @property-read Career $career
 * @property string $plantilla_id
 * @property string $carrera_id
 * @property-read SyllabusTemplate $template
 */
class Syllabus extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'estado' => 'estado_silabo',
        'contexto_academico' => 'contexto_academico_silabo',
    ];

    public $timestamps = false;

    protected $table = 'silabos';

    /** @var list<string> */
    protected $fillable = [
        'convocatoria_id', 'asignatura_id', 'carrera_id', 'plantilla_id', 'estado',
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
        return $this->belongsTo(Convocation::class, 'convocatoria_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'asignatura_id');
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class, 'carrera_id');
    }

    /** @return BelongsTo<SyllabusTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SyllabusTemplate::class, 'plantilla_id');
    }

    /** @return HasMany<SyllabusScope, $this> */
    public function scopes(): HasMany
    {
        return $this->hasMany(SyllabusScope::class, 'silabo_id');
    }

    /**
     * Docentes vigentes: quien tiene hoy la asignación activa de un paralelo del sílabo.
     * El sílabo pertenece al paralelo, no a una persona (I-130).
     *
     * @return HasMany<CurrentSyllabusTeacher, $this>
     */
    public function currentTeachers(): HasMany
    {
        return $this->hasMany(CurrentSyllabusTeacher::class, 'silabo_id');
    }

    /** @return BelongsToMany<User, $this> Usuarios docentes vigentes (ver `currentTeachers`). */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'docentes_vigentes_silabo', 'silabo_id', 'usuario_id');
    }

    /**
     * Sílabos que el usuario puede trabajar hoy como docente vigente de alguno de sus
     * paralelos. No mira el rol activo de la sesión; eso lo resuelve quien consulta.
     *
     * @param  Builder<Syllabus>  $query
     */
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

    /** @return HasMany<RepeatableRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(RepeatableRow::class, 'silabo_id');
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

    /** @return HasMany<Approval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'silabo_id');
    }

    /** @return HasMany<Reopening, $this> */
    public function reopenings(): HasMany
    {
        return $this->hasMany(Reopening::class, 'silabo_id');
    }

    /** @return HasMany<AiExecution, $this> */
    public function aiExecutions(): HasMany
    {
        return $this->hasMany(AiExecution::class, 'silabo_id');
    }

    /** @return HasMany<SyllabusTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(SyllabusTransition::class, 'silabo_id');
    }
}
