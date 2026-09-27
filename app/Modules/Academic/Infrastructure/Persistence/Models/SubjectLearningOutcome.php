<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $base_resultados_id
 * @property string $texto_resultado
 * @property int $posicion_resultado
 */
class SubjectLearningOutcome extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'resultados_aprendizaje_asignatura';

    /** @var list<string> */
    protected $fillable = ['base_resultados_id', 'texto_resultado', 'posicion_resultado'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['posicion_resultado' => 'integer'];
    }

    /** @return BelongsTo<SubjectLearningOutcomeBase, $this> */
    public function base(): BelongsTo
    {
        return $this->belongsTo(SubjectLearningOutcomeBase::class, 'base_resultados_id');
    }
}
