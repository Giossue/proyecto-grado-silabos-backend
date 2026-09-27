<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $asignatura_id
 * @property string $nombre_fuente
 * @property string $localizador_fuente
 * @property string $confirmado_por
 * @property CarbonImmutable $confirmado_en
 * @property-read User $confirmedBy
 * @property-read Collection<int, SubjectLearningOutcome> $outcomes
 */
class SubjectLearningOutcomeBase extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'bases_resultados_asignatura';

    /** @var list<string> */
    protected $fillable = ['asignatura_id', 'nombre_fuente', 'localizador_fuente', 'confirmado_por', 'confirmado_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['confirmado_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'asignatura_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    /** @return HasMany<SubjectLearningOutcome, $this> */
    public function outcomes(): HasMany
    {
        return $this->hasMany(SubjectLearningOutcome::class, 'base_resultados_id')->orderBy('posicion_resultado');
    }

    /**
     * La fotografía conserva contenido y procedencia sin consultar nuevamente la base.
     *
     * @return array{source_name: string, source_locator: string, confirmed_at: string, confirmed_by: array{id: string, name: string}, outcomes: list<array{id: string, text: string, position: int}>}
     */
    public function snapshot(): array
    {
        $this->loadMissing(['outcomes', 'confirmedBy']);

        return [
            'source_name' => $this->nombre_fuente,
            'source_locator' => $this->localizador_fuente,
            'confirmed_at' => $this->confirmado_en->toIso8601String(),
            'confirmed_by' => ['id' => $this->confirmado_por, 'name' => $this->confirmedBy->nombre],
            'outcomes' => array_values($this->outcomes->map(fn (SubjectLearningOutcome $outcome): array => [
                'id' => $outcome->id,
                'text' => $outcome->texto_resultado,
                'position' => $outcome->posicion_resultado,
            ])->all()),
        ];
    }
}
