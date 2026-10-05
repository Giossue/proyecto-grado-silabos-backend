<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $asignatura_id
 * @property array<int, string>|null $herramientas_tic
 * @property array<int, array{herramienta: string, caracteristica: string}>|null $herramientas_ia
 */
class SubjectProfile extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['id' => 'ficha_asignatura_id'];

    public $timestamps = false;

    protected $table = 'fichas_asignatura';

    protected $primaryKey = 'ficha_asignatura_id';

    protected $fillable = [
        'asignatura_id', 'descripcion', 'objetivo_general', 'objetivos_especificos',
        'resultados_aprendizaje', 'habilidades_blandas', 'bibliografia_basica',
        'bibliografia_complementaria', 'herramientas_tic', 'herramientas_ia',
        'relacion_perfil_egreso',
    ];

    protected function casts(): array
    {
        return [
            'objetivos_especificos' => 'array',
            'resultados_aprendizaje' => 'array',
            'habilidades_blandas' => 'array',
            'bibliografia_basica' => 'array',
            'bibliografia_complementaria' => 'array',
            'herramientas_tic' => 'array',
            'herramientas_ia' => 'array',
        ];
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'asignatura_id');
    }

    public function isComplete(): bool
    {
        foreach (['descripcion', 'objetivo_general', 'relacion_perfil_egreso'] as $key) {
            if (trim((string) $this->getAttribute($key)) === '') {
                return false;
            }
        }
        foreach (['objetivos_especificos', 'resultados_aprendizaje', 'habilidades_blandas', 'herramientas_tic', 'herramientas_ia'] as $key) {
            if ($this->getAttribute($key) === null || $this->getAttribute($key) === []) {
                return false;
            }
        }

        return true;
    }
}
