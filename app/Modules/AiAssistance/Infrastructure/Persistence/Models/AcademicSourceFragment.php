<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $fuente_academica_id
 * @property list<string> $ruta_encabezados
 * @property string|null $titulo_fragmento
 * @property int $posicion_fragmento
 * @property string $contenido_fragmento
 * @property string $huella_fragmento
 * @property-read AcademicSource $source
 */
class AcademicSourceFragment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'fragmentos_fuente_academica';

    /** @var list<string> */
    protected $fillable = [
        'fuente_academica_id', 'ruta_encabezados', 'titulo_fragmento',
        'posicion_fragmento', 'contenido_fragmento', 'huella_fragmento',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ruta_encabezados' => 'array', 'posicion_fragmento' => 'integer'];
    }

    /** @return BelongsTo<AcademicSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(AcademicSource::class, 'fuente_academica_id');
    }
}
