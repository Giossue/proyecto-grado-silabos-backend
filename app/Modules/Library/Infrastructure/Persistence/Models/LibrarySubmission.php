<?php

namespace App\Modules\Library\Infrastructure\Persistence\Models;

use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\Database\MapsLegacyColumnNames;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $revision_silabo_id
 * @property-read SyllabusRevision $revision
 */
class LibrarySubmission extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['id' => 'envio_biblioteca_id'];

    public $timestamps = false;

    protected $table = 'envios_biblioteca';

    protected $primaryKey = 'envio_biblioteca_id';

    /** @var list<string> */
    protected $fillable = ['revision_silabo_id'];

    /** @return BelongsTo<SyllabusRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SyllabusRevision::class, 'revision_silabo_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El envío a biblioteca es inmutable.'));
        static::deleting(fn () => throw new LogicException('El envío a biblioteca no puede eliminarse.'));
    }
}
