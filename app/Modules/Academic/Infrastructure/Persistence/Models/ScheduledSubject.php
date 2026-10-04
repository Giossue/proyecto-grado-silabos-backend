<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Modules\Academic\Domain\CampusLocation;
use App\Modules\Academic\Domain\StudyModality;
use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $periodo_academico_id
 * @property string $asignatura_id
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
        'id' => 'asignatura_ofertada_id',
        'activo' => 'asignatura_ofertada_activa',
    ];

    public $timestamps = false;

    protected $table = 'asignaturas_ofertadas';

    protected $primaryKey = 'asignatura_ofertada_id';

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
        // La carrera o la facultad pueden faltar en datos incompletos: entonces no hay campus.
        $campus = data_get($this->subject, 'career.faculty.campus');

        return $campus instanceof CampusLocation ? $campus->value : null;
    }

    public function getModalidadAttribute(): ?StudyModality
    {
        $this->loadMissing('subject.career');
        $modality = $this->subject->modalidad ?? data_get($this->subject, 'career.modalidad');

        return $modality instanceof StudyModality ? $modality : null;
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
        return $this->hasMany(Parallel::class, 'asignatura_ofertada_id');
    }
}
