<?php

namespace App\Modules\AiAssistance\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $silabo_id
 * @property string $usuario_id
 * @property array<string, mixed> $contenido_mensaje
 * @property CarbonImmutable $enviado_en
 */
class AiHistoryEntry extends Model
{
    use HasUuids;

    public const OPEN_STATES = ['pendiente', 'en_ejecucion'];

    public $timestamps = false;

    protected $table = 'mensajes_ia';

    /** @var list<string> */
    protected $fillable = ['id', 'silabo_id', 'usuario_id', 'contenido_mensaje'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['contenido_mensaje' => 'array', 'enviado_en' => 'immutable_datetime'];
    }

    /** @return array{silabo_id: string, usuario_id: string} */
    public static function chatSession(string $syllabusId, string $userId): array
    {
        return ['silabo_id' => $syllabusId, 'usuario_id' => $userId];
    }

    /**
     * @param  Builder<AiHistoryEntry>  $query
     * @param  array{silabo_id: string, usuario_id: string}  $session
     * @return Builder<AiHistoryEntry>
     */
    public function scopeSession(Builder $query, array $session): Builder
    {
        return $query->where($session);
    }

    /**
     * @param  Builder<AiHistoryEntry>  $query
     * @return Builder<AiHistoryEntry>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('contenido_mensaje->tipo', $type);
    }

    public function type(): string
    {
        return (string) ($this->contenido_mensaje['tipo'] ?? '');
    }

    public function state(): string
    {
        return (string) ($this->contenido_mensaje['estado'] ?? '');
    }

    public function isOpen(): bool
    {
        return in_array($this->state(), self::OPEN_STATES, true);
    }

    public function input(string $key): mixed
    {
        $input = $this->contenido_mensaje['entrada'] ?? [];

        return is_array($input) ? ($input[$key] ?? null) : null;
    }

    public function inputString(string $key): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public function part(string $key): mixed
    {
        return $this->contenido_mensaje[$key] ?? null;
    }

    /** @param array<string, mixed> $changes */
    public function amend(array $changes): void
    {
        $this->update(['contenido_mensaje' => array_replace($this->contenido_mensaje, $changes)]);
    }

    /** @return list<array<string, mixed>> */
    public function recommendations(): array
    {
        $items = $this->contenido_mensaje['recomendaciones'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /** @return array<string, mixed>|null */
    public function recommendation(string $id): ?array
    {
        foreach ($this->recommendations() as $recommendation) {
            if (($recommendation['id'] ?? null) === $id) {
                return $recommendation;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function evidence(): array
    {
        $items = $this->input('evidencia');

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
