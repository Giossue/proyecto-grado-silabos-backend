<?php

namespace App\Modules\Documents\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $silabo_id
 * @property string $revision_silabo_id
 * @property string $firmado_como
 * @property string $usuario_id
 * @property string $declaracion_firmada
 * @property string $firma_cms
 * @property string $nombre_en_certificado
 * @property string $numero_certificado
 * @property string $entidad_emisora
 * @property CarbonImmutable $certificado_valido_hasta
 * @property CarbonImmutable $firmado_en
 * @property-read Syllabus $syllabus
 * @property-read SyllabusRevision $revision
 * @property-read User $signer
 */
class SyllabusSignature extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = [
        'id' => 'firma_silabo_id',
    ];

    public const ROLE_TEACHER = 'docente';

    public const ROLE_COORDINATION = 'coordinacion';

    public const ROLE_LIBRARY = 'biblioteca';

    public const ORDER = [self::ROLE_TEACHER, self::ROLE_LIBRARY, self::ROLE_COORDINATION];

    public $timestamps = false;

    protected $table = 'firmas_silabo';

    protected $primaryKey = 'firma_silabo_id';

    /** @var list<string> */
    protected $fillable = [
        'silabo_id', 'revision_silabo_id', 'firmado_como', 'usuario_id',
        'declaracion_firmada', 'firma_cms', 'nombre_en_certificado', 'numero_certificado',
        'entidad_emisora', 'certificado_valido_hasta', 'firmado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['certificado_valido_hasta' => 'immutable_datetime', 'firmado_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Syllabus, $this> */
    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(Syllabus::class, 'silabo_id');
    }

    /** @return BelongsTo<SyllabusRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SyllabusRevision::class, 'revision_silabo_id');
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Una firma registrada es inmutable.'));
        static::deleting(fn () => throw new LogicException('Una firma solo se borra al reiniciar o eliminar el sílabo.'));
    }
}
