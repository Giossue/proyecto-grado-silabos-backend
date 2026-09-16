<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use App\Modules\Operations\Infrastructure\Persistence\Models\JobExecution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $id
 * @property string $conversacion_ia_id
 * @property string|null $respuesta_a_id
 * @property string|null $ejecucion_trabajo_id
 * @property string $rol_mensaje_ia
 * @property string $estado_mensaje_ia
 * @property string $contenido_mensaje_ia
 * @property int $version_bloqueo_origen
 * @property string|null $mensaje_error
 * @property CarbonImmutable $created_at
 * @property-read AiConversation $conversation
 * @property-read JobExecution|null $jobExecution
 * @property-read Collection<int, AiMessageEvidence> $evidence
 * @property-read Collection<int, AiMessageEvidence> $citations
 */
class AiMessage extends Model
{
    use HasUuids;

    protected $table = 'mensajes_ia';

    /** @var list<string> */
    protected $fillable = [
        'conversacion_ia_id', 'respuesta_a_id', 'ejecucion_trabajo_id', 'clave_idempotencia',
        'rol_mensaje_ia', 'estado_mensaje_ia', 'contenido_mensaje_ia',
        'version_bloqueo_origen', 'version_pasarela_ejecutada', 'codigo_error',
        'mensaje_error', 'completado_en',
    ];

    protected function casts(): array
    {
        return [
            'version_bloqueo_origen' => 'integer',
            'completado_en' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AiConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversacion_ia_id');
    }

    /** @return BelongsTo<JobExecution, $this> */
    public function jobExecution(): BelongsTo
    {
        return $this->belongsTo(JobExecution::class, 'ejecucion_trabajo_id');
    }

    /** @return HasMany<AiMessageEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(AiMessageEvidence::class, 'mensaje_ia_id');
    }

    /** @return BelongsToMany<AiMessageEvidence, $this, Pivot, 'pivot'> */
    public function citations(): BelongsToMany
    {
        return $this->belongsToMany(
            AiMessageEvidence::class,
            'citas_mensaje_ia',
            'mensaje_ia_id',
            'evidencia_mensaje_ia_id',
        )->withPivot('ordinal_cita_mensaje_ia')->orderByPivot('ordinal_cita_mensaje_ia');
    }
}
