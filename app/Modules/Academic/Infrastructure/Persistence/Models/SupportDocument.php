<?php

namespace App\Modules\Academic\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $documento_apoyo_id
 * @property string $carrera_id
 * @property string $titulo_documento
 * @property string $archivo_id
 * @property string|null $archivo_vista_id
 * @property string $estado_vista
 * @property string $subido_por
 * @property CarbonImmutable $subido_en
 * @property-read StoredObject $original
 * @property-read StoredObject|null $preview
 * @property-read User|null $uploader
 */
class SupportDocument extends Model
{
    use HasUuids;

    public const VIEW_PENDING = 'pendiente';

    public const VIEW_READY = 'lista';

    public const VIEW_NONE = 'sin_vista';

    public $timestamps = false;

    protected $table = 'documentos_apoyo';

    protected $primaryKey = 'documento_apoyo_id';

    /** @var list<string> */
    protected $fillable = [
        'documento_apoyo_id', 'carrera_id', 'titulo_documento', 'archivo_id', 'archivo_vista_id',
        'estado_vista', 'subido_por', 'subido_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['subido_en' => 'immutable_datetime'];
    }

    /** @return BelongsTo<StoredObject, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(StoredObject::class, 'archivo_id');
    }

    /** @return BelongsTo<StoredObject, $this> */
    public function preview(): BelongsTo
    {
        return $this->belongsTo(StoredObject::class, 'archivo_vista_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
