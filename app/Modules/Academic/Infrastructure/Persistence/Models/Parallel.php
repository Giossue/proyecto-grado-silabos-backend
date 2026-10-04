<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $codigo
 * @property string $jornada
 * @property-read ScheduledSubject $scheduledSubject
 */
class Parallel extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'paralelo_id',
        'codigo' => 'codigo_paralelo',
        'jornada' => 'jornada_paralelo',
        'programacion_asignatura_id' => 'asignatura_ofertada_id',
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
    protected $fillable = ['programacion_asignatura_id', 'codigo', 'jornada'];

    /** @return BelongsTo<ScheduledSubject, $this> */
    public function scheduledSubject(): BelongsTo
    {
        return $this->belongsTo(ScheduledSubject::class, 'asignatura_ofertada_id');
    }

    /** @return HasMany<TeacherAssignment, $this> */
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'paralelo_id');
    }
}
