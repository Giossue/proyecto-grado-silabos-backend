<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property string $id
 * @property string $nombre
 * @property string $codigo_carrera
 * @property string $facultad_id
 * @property StudyModality|null $modalidad
 * @property int $cantidad_ciclos_malla
 * @property bool $activo
 */
class Career extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'carrera_id',
        'nombre' => 'nombre_carrera',
        'activo' => 'carrera_activa',
        'modalidad' => 'modalidad_carrera',
        'cantidad_ciclos_malla' => 'numero_ciclos',
    ];

    public $timestamps = false;

    protected $table = 'carreras';

    protected $primaryKey = 'carrera_id';

    /** @var list<string> */
    protected $fillable = [
        'facultad_id', 'modalidad', 'codigo_carrera', 'nombre', 'activo',
        'cantidad_ciclos_malla',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'modalidad' => StudyModality::class,
            'cantidad_ciclos_malla' => 'integer',
        ];
    }

    /** @return BelongsTo<Faculty, $this> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class, 'facultad_id');
    }

    /** @return HasMany<CoordinatorAssignment, $this> */
    public function coordinatorAssignments(): HasMany
    {
        return $this->hasMany(CoordinatorAssignment::class, 'carrera_id');
    }

    /** @return HasMany<Subject, $this> */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class, 'carrera_id');
    }

    /** @return HasManyThrough<Syllabus, Convocation, $this> Sílabos de sus convocatorias (I-131). */
    public function syllabi(): HasManyThrough
    {
        return $this->hasManyThrough(Syllabus::class, Convocation::class, 'carrera_id', 'convocatoria_carrera_id');
    }
}
