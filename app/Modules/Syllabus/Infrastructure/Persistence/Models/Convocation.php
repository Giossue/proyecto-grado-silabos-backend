<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models;

use App\Modules\Academic\Infrastructure\Persistence\Models\Career;
use App\Support\Database\MapsLegacyColumnNames;
use App\Support\InstitutionDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $carrera_id
 * @property string $proceso_id
 * @property string $estado
 * @property-read string $nombre
 * @property-read Career $career
 * @property-read SyllabusProcess $process
 */
class Convocation extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'convocatoria_carrera_id',
        'estado' => 'estado_convocatoria_carrera',
        'proceso_id' => 'convocatoria_universidad_id',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('proceso_no_eliminado', fn (Builder $query) => $query->whereHas('process'));
    }

    public $timestamps = false;

    public const STATE_PREPARATION = 'preparacion';

    public const STATE_OPEN = 'abierta';

    public const STATE_PAUSED = 'pausada';

    public const STATE_CLOSED = 'cerrada';

    protected $table = 'convocatorias_carreras';

    protected $primaryKey = 'convocatoria_carrera_id';

    /** @var list<string> */
    protected $fillable = [
        'carrera_id', 'proceso_id', 'estado',
    ];

    /** @param  Builder<Convocation>  $query */
    public function scopeRunning(Builder $query): void
    {
        $query->where('estado', self::STATE_OPEN)
            ->whereHas('process', fn (Builder $process) => $process->where('estado', SyllabusProcess::STATE_OPEN));
    }

    /** @param  Builder<Convocation>  $query */
    public function scopeLockingCareerData(Builder $query): void
    {
        $query->where('estado', self::STATE_OPEN)
            ->whereHas('process', fn (Builder $process) => $process->whereIn('estado', [
                SyllabusProcess::STATE_OPEN,
                SyllabusProcess::STATE_PAUSED,
            ]));
    }

    public function deadlinePassed(): bool
    {
        return InstitutionDate::todayOn() > InstitutionDate::dateOf($this->process->entrega_en);
    }

    public function isRunning(): bool
    {
        return $this->estado === self::STATE_OPEN
            && $this->process()->value('estado') === SyllabusProcess::STATE_OPEN;
    }

    /** @return BelongsTo<SyllabusProcess, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(SyllabusProcess::class, 'convocatoria_universidad_id');
    }

    /** @return Attribute<string, never> */
    protected function nombre(): Attribute
    {
        return Attribute::get(function (): string {
            $career = $this->relationLoaded('career')
                ? $this->career->nombre
                : $this->career()->value('nombre');
            $process = $this->relationLoaded('process')
                ? $this->process
                : $this->process()->with('academicPeriod:id,codigo')->firstOrFail();
            $period = $process->relationLoaded('academicPeriod')
                ? $process->academicPeriod->codigo
                : $process->academicPeriod()->value('codigo');

            return trim(($career ?? 'Carrera').' · '.($period ?? 'Período'));
        });
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class, 'carrera_id');
    }

    /** @return HasMany<Syllabus, $this> */
    public function syllabi(): HasMany
    {
        return $this->hasMany(Syllabus::class, 'convocatoria_carrera_id');
    }
}
