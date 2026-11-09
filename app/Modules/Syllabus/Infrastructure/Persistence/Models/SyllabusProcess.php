<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Support\Database\MapsLegacyColumnNames;
use App\Support\InstitutionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @property string $id
 * @property string $periodo_academico_id
 * @property CarbonImmutable $inicia_en
 * @property CarbonImmutable $entrega_en
 * @property CarbonImmutable|null $pausado_en
 * @property CarbonImmutable|null $finalizada_en
 * @property string $estado
 * @property int $cantidad_prorrogas
 * @property-read string $nombre
 */
class SyllabusProcess extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'convocatoria_universidad_id',
        'estado' => 'estado_convocatoria_universidad',
        'inicia_en' => 'fecha_inicio',
        'entrega_en' => 'fecha_entrega',
    ];

    public $timestamps = false;

    public const STATE_OPEN = 'abierto';

    public const STATE_PAUSED = 'pausado';

    public const STATE_CLOSED = 'cerrado';

    public const MAX_EXTENSIONS = 2;

    /** @var list<string> */
    public const STATES = [
        self::STATE_OPEN,
        self::STATE_PAUSED,
        self::STATE_CLOSED,
    ];

    protected $table = 'convocatorias_universidad';

    protected $primaryKey = 'convocatoria_universidad_id';

    /** @var list<string> */
    protected $fillable = [
        'periodo_academico_id', 'inicia_en', 'entrega_en', 'estado', 'pausado_en', 'finalizada_en', 'cantidad_prorrogas',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'inicia_en' => 'immutable_datetime',
            'entrega_en' => 'immutable_datetime',
            'pausado_en' => 'immutable_datetime',
            'finalizada_en' => 'immutable_datetime',
            'cantidad_prorrogas' => 'integer',
        ];
    }

    /** @return Attribute<string, never> */
    protected function nombre(): Attribute
    {
        return Attribute::get(fn (): string => $this->relationLoaded('academicPeriod')
            ? $this->academicPeriod->codigo
            : ($this->academicPeriod()->value('codigo') ?? 'Período'));
    }

    /**
     * La convocatoria institucional actual: la más reciente, esté abierta, en pausa o finalizada.
     * Al abrir una nueva, la anterior pasa entera al historial (lo que no se terminó queda como estaba).
     */
    public static function currentId(): ?string
    {
        $id = static::query()->orderByDesc('inicia_en')->orderByDesc('convocatoria_universidad_id')->value('convocatoria_universidad_id');

        return is_string($id) ? $id : null;
    }

    /** @param  Builder<SyllabusProcess>  $query */
    public function scopeInProgress(Builder $query): void
    {
        $query->whereIn('estado', [self::STATE_OPEN, self::STATE_PAUSED]);
    }

    /** @param  Builder<SyllabusProcess>  $query */
    public function scopeFinalizedButCurrent(Builder $query): void
    {
        $own = $query->getModel()->getTable();
        $query->where('estado', self::STATE_CLOSED)
            ->whereHas('academicPeriod', fn (Builder $period) => $period->where('fecha_fin', '>=', InstitutionDate::todayOn()))
            ->whereNotExists(fn (QueryBuilder $newer) => $newer->selectRaw('1')
                ->from('convocatorias_universidad as posterior')
                ->join('periodos_academicos as periodo_posterior', 'periodo_posterior.periodo_academico_id', '=', 'posterior.periodo_academico_id')
                ->join('periodos_academicos as periodo_propio', 'periodo_propio.periodo_academico_id', '=', "{$own}.periodo_academico_id")
                ->whereColumn('posterior.convocatoria_universidad_id', '<>', "{$own}.convocatoria_universidad_id")
                ->where(fn (QueryBuilder $later) => $later
                    ->whereIn('posterior.estado_convocatoria_universidad', [self::STATE_OPEN, self::STATE_PAUSED])
                    ->orWhereColumn('periodo_posterior.fecha_inicio_periodo', '>', 'periodo_propio.fecha_inicio_periodo')));
    }

    public function isOpen(): bool
    {
        return $this->estado === self::STATE_OPEN;
    }

    public function isConfigurable(): bool
    {
        return $this->estado === self::STATE_PAUSED;
    }

    public function isDeletable(): bool
    {
        return $this->estado !== self::STATE_CLOSED;
    }

    /** @return BelongsTo<AcademicPeriod, $this> */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'periodo_academico_id');
    }

    /** @return HasMany<Convocation, $this> */
    public function convocations(): HasMany
    {
        return $this->hasMany(Convocation::class, 'convocatoria_universidad_id');
    }
}
