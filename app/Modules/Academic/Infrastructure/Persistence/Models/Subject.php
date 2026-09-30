<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Modules\Academic\Domain\CurricularUnit;
use App\Modules\Academic\Domain\StudyModality;
use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $codigo_asignatura
 * @property string $nombre
 * @property int|null $ciclo
 * @property int $orden_en_ciclo
 * @property CurricularUnit|null $unidad_organizacion_curricular
 * @property StudyModality|null $modalidad
 * @property string|null $creditos
 * @property-read int|null $horas_totales Columna generada: ACD + APE + AA (I-132).
 * @property string|null $horas_ac
 * @property string|null $horas_pae
 * @property string|null $horas_aa
 * @property bool $activo
 * @property string $carrera_id
 * @property-read Career $career
 */
class Subject extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'nombre' => 'nombre_asignatura',
        'ciclo' => 'ciclo_asignatura',
        'creditos' => 'creditos_asignatura',
        'horas_totales' => 'total_horas_asignatura',
        'activo' => 'asignatura_activa',
        'orden_en_ciclo' => 'orden_asignatura_en_ciclo',
        'unidad_organizacion_curricular' => 'unidad_curricular_asignatura',
        'horas_ac' => 'horas_docencia',
        'horas_pae' => 'horas_practicas',
        'horas_aa' => 'horas_autonomas',
        'modalidad' => 'modalidad_asignatura',
    ];

    public $timestamps = false;

    protected $table = 'asignaturas';

    /** @var list<string> */
    protected $fillable = [
        'carrera_id',
        'codigo_asignatura',
        'nombre',
        'ciclo',
        'orden_en_ciclo',
        'unidad_organizacion_curricular',
        'modalidad',
        'creditos',
        'horas_ac',
        'horas_pae',
        'horas_aa',
        'activo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ciclo' => 'integer',
            'unidad_organizacion_curricular' => CurricularUnit::class,
            'modalidad' => StudyModality::class,
            'orden_en_ciclo' => 'integer',
            'creditos' => 'decimal:2',
            'horas_totales' => 'integer',
            'horas_ac' => 'decimal:2',
            'horas_pae' => 'decimal:2',
            'horas_aa' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    /** @param  array<int, string>|null  $except */
    public function replicate(?array $except = null): static
    {
        return parent::replicate([...($except ?? []), 'total_horas_asignatura']);
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class, 'carrera_id');
    }

    /** @return HasMany<ScheduledSubject, $this> */
    public function scheduledSubjects(): HasMany
    {
        return $this->hasMany(ScheduledSubject::class, 'asignatura_id');
    }

    /** @return HasMany<SubjectRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(SubjectRequirement::class, 'asignatura_id');
    }

    /** @return HasMany<SubjectRequirement, $this> */
    public function requiredBy(): HasMany
    {
        return $this->hasMany(SubjectRequirement::class, 'requisito_id');
    }
}
