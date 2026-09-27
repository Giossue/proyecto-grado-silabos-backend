<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $concepto_id
 * @property string $carrera_id
 * @property string|null $asignatura_id
 * @property string|list<string>|list<array<string, string>> $valor_curricular
 * @property list<array<string, mixed>> $evidencia_curricular
 * @property string $confirmado_por
 * @property CarbonImmutable $confirmado_en
 * @property-read CurricularConcept $concept
 */
class CurricularValue extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'valores_curriculares';

    /** @var list<string> */
    protected $fillable = ['concepto_id', 'carrera_id', 'asignatura_id', 'valor_curricular', 'evidencia_curricular', 'confirmado_por', 'confirmado_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valor_curricular' => 'json', 'evidencia_curricular' => 'array', 'confirmado_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CurricularConcept, $this> */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(CurricularConcept::class, 'concepto_id');
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class, 'carrera_id');
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
}
