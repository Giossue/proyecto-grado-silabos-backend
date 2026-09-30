<?php

namespace App\Modules\Syllabus\Infrastructure\Persistence\Models;

use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $periodo_academico_id
 * @property CarbonImmutable $inicia_en
 * @property CarbonImmutable $entrega_en
 * @property string $estado
 * @property-read string $nombre
 */
class SyllabusProcess extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['estado' => 'estado_convocatoria_universidad'];

    public $timestamps = false;

    public const STATE_OPEN = 'abierto';

    public const STATE_PAUSED = 'pausado';

    public const STATE_CLOSED = 'cerrado';

    /** @var list<string> */
    public const STATES = [
        self::STATE_OPEN,
        self::STATE_PAUSED,
        self::STATE_CLOSED,
    ];

    protected $table = 'convocatorias_universidad';

    /** @var list<string> */
    protected $fillable = [
        'periodo_academico_id', 'inicia_en', 'entrega_en', 'estado',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'inicia_en' => 'immutable_datetime',
            'entrega_en' => 'immutable_datetime',
        ];
    }

    /** @return Attribute<string, never> */
    protected function nombre(): Attribute
    {
        return Attribute::get(fn (): string => $this->relationLoaded('academicPeriod')
            ? $this->academicPeriod->codigo
            : ($this->academicPeriod()->value('codigo') ?? 'Período'));
    }

    /** @param  Builder<SyllabusProcess>  $query */
    public function scopeInProgress(Builder $query): void
    {
        $query->whereIn('estado', [self::STATE_OPEN, self::STATE_PAUSED]);
    }

    public function isOpen(): bool
    {
        return $this->estado === self::STATE_OPEN;
    }

    public function isConfigurable(): bool
    {
        return $this->estado === self::STATE_PAUSED;
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
