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

    /** Nombres que comparten dos o más carreras: se calcula una vez por petición o trabajo de la cola. */
    private const SHARED_NAMES = 'carreras.nombres_compartidos';

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

    protected static function booted(): void
    {
        static::saved(fn () => app()->forgetInstance(self::SHARED_NAMES));
        static::deleted(fn () => app()->forgetInstance(self::SHARED_NAMES));
    }

    /**
     * Nombre para las pantallas. Si otra carrera se llama igual (por ejemplo, dos mallas de
     * una misma carrera), agrega el código para distinguirlas. El sílabo usa solo el nombre.
     */
    public function label(): string
    {
        if (! app()->bound(self::SHARED_NAMES)) {
            app()->scoped(self::SHARED_NAMES, fn (): array => array_fill_keys(
                static::query()->groupBy('nombre_carrera')->havingRaw('COUNT(*) > 1')->pluck('nombre_carrera')->all(),
                true,
            ));
        }
        /** @var array<string, true> $shared */
        $shared = app(self::SHARED_NAMES);

        return isset($shared[$this->nombre]) ? "{$this->nombre} · {$this->codigo_carrera}" : $this->nombre;
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
