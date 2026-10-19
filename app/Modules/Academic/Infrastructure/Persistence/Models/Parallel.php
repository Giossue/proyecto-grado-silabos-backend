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
 * @property string $asignatura_id
 * @property string $codigo
 * @property string $jornada
 * @property-read Subject $subject
 * @property-read string|null $campus_programacion
 * @property-read StudyModality|null $modalidad
 */
class Parallel extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'paralelo_id',
        'codigo' => 'codigo_paralelo',
        'jornada' => 'jornada_paralelo',
    ];

    public $timestamps = false;

    protected $table = 'paralelos';

    protected $primaryKey = 'paralelo_id';

    /** @var list<string> */
    public const SHIFTS = ['matutina', 'vespertina', 'nocturna'];

    /** @return list<array{value: string, label: string}> */
    public static function shiftOptions(): array
    {
        return array_map(fn (string $shift): array => ['value' => $shift, 'label' => ucfirst($shift)], self::SHIFTS);
    }

    /** @var list<string> */
    protected $fillable = ['asignatura_id', 'codigo', 'jornada'];

    public function getCampusProgramacionAttribute(): ?string
    {
        $this->loadMissing('subject');

        return $this->subject->campusName();
    }

    public function getModalidadAttribute(): ?StudyModality
    {
        $this->loadMissing('subject');

        return $this->subject->studyModality();
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'asignatura_id');
    }

    /** @return HasMany<TeacherAssignment, $this> */
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'paralelo_id');
    }
}
