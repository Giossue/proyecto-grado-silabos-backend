<?php

namespace App\Modules\Documents\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $disco
 * @property string $ruta_interna
 * @property string $nombre_logico
 * @property string $mime
 * @property int $tamano_bytes
 * @property string $huella_sha256
 * @property string $clasificacion
 * @property string|null $propietario_usuario_id
 * @property string|null $carrera_id
 * @property CarbonImmutable $almacenado_en
 */
class StoredObject extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'archivo_id',
        'nombre_logico' => 'nombre_archivo',
        'mime' => 'tipo_archivo',
        'clasificacion' => 'categoria',
        'propietario_usuario_id' => 'subido_por',
        'ruta_interna' => 'ruta_archivo',
        'huella_sha256' => 'hash_archivo',
    ];

    public const CREATED_AT = 'almacenado_en';

    public const UPDATED_AT = null;

    protected $table = 'archivos';

    protected $primaryKey = 'archivo_id';

    /** @var list<string> */
    protected $fillable = [
        'disco', 'ruta_interna', 'nombre_logico', 'mime', 'tamano_bytes',
        'huella_sha256', 'clasificacion', 'propietario_usuario_id',
        'carrera_id', 'almacenado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['tamano_bytes' => 'integer', 'almacenado_en' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un objeto publicado es inmutable.'));
        static::deleting(fn () => throw new LogicException('La retención no permite eliminar objetos.'));
    }
}
