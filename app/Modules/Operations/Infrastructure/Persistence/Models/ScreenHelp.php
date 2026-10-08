<?php

namespace App\Modules\Operations\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $pantalla
 * @property string $titulo_ayuda
 * @property string $contenido_ayuda
 * @property bool $activa
 * @property string|null $actualizado_por
 * @property CarbonImmutable $editada_en_ayuda
 */
class ScreenHelp extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['id' => 'ayuda_pantalla_id'];

    public $timestamps = false;

    protected $table = 'ayudas_pantalla';

    protected $primaryKey = 'ayuda_pantalla_id';

    /** @var list<string> */
    protected $fillable = ['pantalla', 'titulo_ayuda', 'contenido_ayuda', 'activa', 'actualizado_por', 'editada_en_ayuda'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activa' => 'boolean', 'editada_en_ayuda' => 'immutable_datetime'];
    }
}
