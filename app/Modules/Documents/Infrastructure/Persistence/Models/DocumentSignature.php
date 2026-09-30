<?php

namespace App\Modules\Documents\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $artefacto_exportacion_id
 * @property string $rol_firma
 * @property string $usuario_id
 * @property string $objeto_almacenado_id
 * @property string $certificado_titular
 * @property string $certificado_serie
 * @property string $certificado_emisor
 * @property CarbonImmutable $certificado_vence_en
 * @property string $huella_sha256
 * @property CarbonImmutable $firmado_en
 * @property-read ExportArtifact $artifact
 * @property-read StoredObject $storedObject
 * @property-read User $signer
 */
class DocumentSignature extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'artefacto_exportacion_id' => 'exportacion_silabo_id',
        'rol_firma' => 'firmado_como',
        'objeto_almacenado_id' => 'archivo_firmado_id',
        'certificado_titular' => 'nombre_en_certificado',
        'certificado_serie' => 'numero_certificado',
        'certificado_emisor' => 'entidad_emisora',
        'certificado_vence_en' => 'certificado_valido_hasta',
        'huella_sha256' => 'huella_archivo',
    ];

    public const ROLE_TEACHER = 'docente';

    public const ROLE_COORDINATION = 'coordinacion';

    public const ROLE_LIBRARY = 'biblioteca';

    public const ORDER = [self::ROLE_TEACHER, self::ROLE_COORDINATION, self::ROLE_LIBRARY];

    public $timestamps = false;

    protected $table = 'firmas_documento';

    /** @var list<string> */
    protected $fillable = [
        'artefacto_exportacion_id', 'rol_firma', 'usuario_id', 'objeto_almacenado_id',
        'certificado_titular', 'certificado_serie', 'certificado_emisor',
        'certificado_vence_en', 'huella_sha256', 'firmado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['certificado_vence_en' => 'immutable_datetime', 'firmado_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ExportArtifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ExportArtifact::class, 'exportacion_silabo_id');
    }

    /** @return BelongsTo<StoredObject, $this> */
    public function storedObject(): BelongsTo
    {
        return $this->belongsTo(StoredObject::class, 'archivo_firmado_id');
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Una firma registrada es inmutable.'));
        static::deleting(fn () => throw new LogicException('La retención no permite eliminar firmas.'));
    }
}
