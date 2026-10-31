<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $plantilla_id
 * @property string $mensaje_ia_plantilla_id
 * @property string $solicitada_por
 * @property array{version: int, sections: list<array{key: string, title: string, orientation: string, fields: list<array{key: string, label: string, quien_llena: string, content: array<string, mixed>}>}>} $estructura
 * @property array{lista: list<string>, secciones: list<string>, campos: list<string>} $cambios
 * @property string $huella_base
 * @property string $estado
 * @property string|null $decidida_por
 * @property CarbonImmutable|null $decidida_en
 * @property CarbonImmutable $creada_en
 */
class TemplateAssistantProposal extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    public const PENDING = 'pendiente';

    public const APPLIED = 'aplicada';

    public const DISCARDED = 'descartada';

    public const STALE = 'vencida';

    public const REPLACED = 'reemplazada';

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'propuesta_ia_plantilla_id',
        'estructura' => 'estructura_propuesta',
        'cambios' => 'cambios_propuesta',
        'huella_base' => 'huella_plantilla_base',
        'estado' => 'estado_propuesta',
    ];

    public $timestamps = false;

    protected $table = 'propuestas_ia_plantilla';

    protected $primaryKey = 'propuesta_ia_plantilla_id';

    /** @var list<string> */
    protected $fillable = ['id', 'plantilla_id', 'mensaje_ia_plantilla_id', 'solicitada_por', 'estructura', 'cambios', 'huella_base', 'estado', 'decidida_por', 'decidida_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['estructura' => 'array', 'cambios' => 'array', 'decidida_en' => 'immutable_datetime', 'creada_en' => 'immutable_datetime'];
    }
}
