<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $silabo_id
 * @property int $version_bloqueo_origen
 * @property string $clave_idempotencia
 * @property string $estado_revision_ia
 * @property int $total_campos
 * @property int $campos_finalizados
 * @property int $campos_con_recomendaciones
 * @property int $campos_fallidos
 * @property string $solicitado_por
 * @property string|null $asignacion_rol_id
 * @property CarbonImmutable $solicitado_en
 * @property CarbonImmutable|null $iniciado_en
 * @property CarbonImmutable|null $completado_en
 */
class SyllabusAiReview extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'revisiones_ia_silabo';

    /** @var list<string> */
    protected $fillable = [
        'silabo_id', 'version_bloqueo_origen', 'clave_idempotencia', 'estado_revision_ia',
        'total_campos', 'campos_finalizados', 'campos_con_recomendaciones',
        'campos_fallidos', 'solicitado_por', 'asignacion_rol_id', 'solicitado_en',
        'iniciado_en', 'completado_en',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version_bloqueo_origen' => 'integer',
            'total_campos' => 'integer',
            'campos_finalizados' => 'integer',
            'campos_con_recomendaciones' => 'integer',
            'campos_fallidos' => 'integer',
            'solicitado_en' => 'immutable_datetime',
            'iniciado_en' => 'immutable_datetime',
            'completado_en' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Syllabus, $this> */
    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(Syllabus::class, 'silabo_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    /** @return HasMany<AiExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(AiExecution::class, 'revision_ia_silabo_id');
    }

    protected static function booted(): void
    {
        static::updating(function (SyllabusAiReview $review): void {
            if (in_array($review->getOriginal('estado_revision_ia'), ['completada', 'no_concluyente', 'parcial', 'fallida'], true)) {
                throw new LogicException('Una revisión integral de IA terminal es inmutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Una revisión integral de IA no puede eliminarse.'));
    }
}
