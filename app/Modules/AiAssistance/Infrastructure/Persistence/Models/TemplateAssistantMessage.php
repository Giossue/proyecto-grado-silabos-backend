<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use App\Support\Database\MapsLegacyColumnNames;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $plantilla_id
 * @property string $usuario_id
 * @property array<string, mixed> $contenido_mensaje
 * @property CarbonImmutable $enviado_en
 * @property CarbonImmutable|null $ocultado_en
 * @property-read TemplateAssistantProposal|null $proposal
 */
class TemplateAssistantMessage extends Model
{
    use HasUuids, MapsLegacyColumnNames;

    protected const LEGACY_COLUMN_ALIASES = ['id' => 'mensaje_ia_plantilla_id'];

    public const OPEN_STATES = ['pendiente', 'en_ejecucion'];

    public $timestamps = false;

    protected $table = 'mensajes_ia_plantilla';

    protected $primaryKey = 'mensaje_ia_plantilla_id';

    /** @var list<string> */
    protected $fillable = ['id', 'plantilla_id', 'usuario_id', 'contenido_mensaje', 'ocultado_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['contenido_mensaje' => 'array', 'enviado_en' => 'immutable_datetime', 'ocultado_en' => 'immutable_datetime'];
    }

    /** @return HasOne<TemplateAssistantProposal, $this> */
    public function proposal(): HasOne
    {
        return $this->hasOne(TemplateAssistantProposal::class, 'mensaje_ia_plantilla_id', 'mensaje_ia_plantilla_id');
    }

    /**
     * @param  Builder<TemplateAssistantMessage>  $query
     * @return Builder<TemplateAssistantMessage>
     */
    public function scopeConversation(Builder $query, string $templateId, string $userId): Builder
    {
        return $query->where('plantilla_id', $templateId)->where('usuario_id', $userId)->whereNull('ocultado_en');
    }

    public function role(): string
    {
        return (string) ($this->contenido_mensaje['rol'] ?? '');
    }

    public function state(): string
    {
        return (string) ($this->contenido_mensaje['estado'] ?? '');
    }

    public function isOpen(): bool
    {
        return in_array($this->state(), self::OPEN_STATES, true);
    }

    public function part(string $key): mixed
    {
        return $this->contenido_mensaje[$key] ?? null;
    }

    public function text(): string
    {
        $value = $this->role() === 'usuario' ? data_get($this->contenido_mensaje, 'entrada.contenido') : $this->part('contenido');

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $changes */
    public function amend(array $changes): void
    {
        $this->update(['contenido_mensaje' => array_replace($this->contenido_mensaje, $changes)]);
    }
}
