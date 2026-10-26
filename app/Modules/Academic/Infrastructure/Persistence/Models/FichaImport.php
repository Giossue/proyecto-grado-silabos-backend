<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $carrera_id
 * @property string $solicitado_por
 * @property string $estado_importacion
 * @property list<array{name: string, path: string, size: int}> $documentos_importacion
 * @property array{format?: string, fingerprint?: string, subjects?: list<array<string, mixed>>, reading?: array{index: int, total: int, name: string, ocr: string|null, started_at: string}, timing?: array<string, mixed>}|null $propuesta_importacion
 * @property string|null $mensaje_error_importacion
 * @property CarbonImmutable $solicitado_en
 * @property CarbonImmutable|null $aplicado_en
 */
class FichaImport extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['id' => 'importacion_ficha_id'];

    public const PENDING = 'pendiente';

    public const RUNNING = 'en_ejecucion';

    public const READY = 'lista';

    public const FAILED = 'fallida';

    public const APPLIED = 'aplicada';

    public const DISCARDED = 'descartada';

    public $timestamps = false;

    protected $table = 'importaciones_ficha';

    protected $primaryKey = 'importacion_ficha_id';

    /** @var list<string> */
    protected $fillable = ['carrera_id', 'solicitado_por', 'estado_importacion', 'documentos_importacion', 'propuesta_importacion', 'mensaje_error_importacion', 'solicitado_en', 'aplicado_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['documentos_importacion' => 'array', 'propuesta_importacion' => 'array', 'solicitado_en' => 'immutable_datetime', 'aplicado_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class, 'carrera_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function inProgress(): bool
    {
        return in_array($this->estado_importacion, [self::PENDING, self::RUNNING, self::READY, self::FAILED], true);
    }
}
