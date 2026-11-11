<?php

namespace App\Modules\Library\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $revision_silabo_id
 * @property string|null $verificado_por
 * @property CarbonImmutable|null $verificado_en
 * @property bool $verificacion_heredada
 * @property-read SyllabusRevision $revision
 * @property-read User|null $verifier
 */
class LibrarySubmission extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['id' => 'envio_biblioteca_id'];

    public $timestamps = false;

    protected $table = 'envios_biblioteca';

    protected $primaryKey = 'envio_biblioteca_id';

    /** @var list<string> */
    protected $fillable = ['revision_silabo_id', 'verificado_por', 'verificado_en', 'verificacion_heredada'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verificado_en' => 'immutable_datetime', 'verificacion_heredada' => 'boolean'];
    }

    /** @return BelongsTo<SyllabusRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SyllabusRevision::class, 'revision_silabo_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificado_por');
    }

    public function verified(): bool
    {
        return $this->verificado_en !== null;
    }

    protected static function booted(): void
    {
        static::updating(function (self $submission): void {
            $changed = array_keys($submission->getDirty());
            if ($submission->getOriginal('verificado_en') !== null || array_diff($changed, ['verificado_por', 'verificado_en']) !== []) {
                throw new LogicException('El envío a biblioteca solo admite registrar su verificación una vez.');
            }
        });
        static::deleting(fn () => throw new LogicException('El envío a biblioteca no puede eliminarse.'));
    }
}
