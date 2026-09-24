<?php

namespace App\Modules\Documents\Infrastructure\Persistence\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * I-139. Firma electrónica aceptada sobre el PDF de un artefacto de exportación.
 *
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
    use HasUuids;

    public const ROLE_TEACHER = 'docente';

    public const ROLE_COORDINATION = 'coordinacion';

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
        return $this->belongsTo(ExportArtifact::class, 'artefacto_exportacion_id');
    }

    /** @return BelongsTo<StoredObject, $this> */
    public function storedObject(): BelongsTo
    {
        return $this->belongsTo(StoredObject::class, 'objeto_almacenado_id');
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
