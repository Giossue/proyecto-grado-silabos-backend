<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Modules\Academic\Domain\StudyModality;
use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property bool $activo
 * @property int $parallels_count
 * @property-read AcademicPeriod $academicPeriod
 * @property-read Subject $subject
 * @property-read string|null $campus_programacion Campus de la facultad de la carrera (derivado, I-164).
 * @property-read StudyModality|null $modalidad Modalidad de la materia o, si no tiene, de la carrera (derivada).
 */
class ScheduledSubject extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'activo' => 'programacion_asignatura_activa',
    ];

    public $timestamps = false;

    protected $table = 'programaciones_asignatura';

    /** @var list<string> */
    protected $fillable = ['periodo_academico_id', 'asignatura_id', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    // Campus y modalidad no se guardan en la programación: salen de la materia, su carrera y su facultad.
    public function getCampusProgramacionAttribute(): ?string
    {
        $this->loadMissing('subject.career.faculty');

        return $this->subject->career->faculty?->campus?->value;
    }

    public function getModalidadAttribute(): ?StudyModality
    {
        $this->loadMissing('subject.career');

        return $this->subject->modalidad ?? $this->subject->career->modalidad;
    }

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'periodo_academico_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'asignatura_id');
    }

    /** @return HasMany<Parallel, $this> */
    public function parallels(): HasMany
    {
        return $this->hasMany(Parallel::class, 'programacion_asignatura_id');
    }
}
