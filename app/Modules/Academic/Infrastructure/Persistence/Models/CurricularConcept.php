<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $clave_concepto
 * @property string $etiqueta_concepto
 * @property string $tipo_concepto
 * @property string $ambito_concepto
 * @property list<array{key: string, label: string}>|null $columnas_concepto
 */
class CurricularConcept extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'conceptos_curriculares';

    /** @var list<string> */
    protected $fillable = ['clave_concepto', 'etiqueta_concepto', 'tipo_concepto', 'ambito_concepto', 'columnas_concepto'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['columnas_concepto' => 'array'];
    }

    /** @return HasMany<CurricularValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(CurricularValue::class, 'concepto_id');
    }

    /** @return array{key: string, label: string, type: string, scope: string, columns: list<array{key: string, label: string}>|null} */
    public function definition(): array
    {
        return [
            'key' => $this->clave_concepto, 'label' => $this->etiqueta_concepto,
            'type' => $this->tipo_concepto, 'scope' => $this->ambito_concepto,
            'columns' => $this->columnas_concepto,
        ];
    }
}
