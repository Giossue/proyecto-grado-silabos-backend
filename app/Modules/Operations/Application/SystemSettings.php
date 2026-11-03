<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ajustes que Administración cambia desde «Configuración › Sistema»: se guardan en `ajustes_sistema` y se
 * aplican sobre la configuración. Mientras un ajuste no se guardó, vale la variable de entorno.
 * La clave de la IA se guarda cifrada con APP_KEY y nunca sale del servidor (ni siquiera una parte).
 *
 * @phpstan-type FieldValue array{value: mixed, source: string}
 */
final class SystemSettings
{
    public const SECRET = 'ai.api_key';

    /** @var array<string, array{section: string, config: string}> */
    public const FIELDS = [
        'notifications.emails' => ['section' => 'notificaciones', 'config' => 'notifications.email.enabled'],
        'notifications.digest_time' => ['section' => 'notificaciones', 'config' => 'notifications.digest.time'],
        'ai.driver' => ['section' => 'ia', 'config' => 'ai.driver'],
        'ai.base_url' => ['section' => 'ia', 'config' => 'ai.hosted.base_url'],
        'ai.model' => ['section' => 'ia', 'config' => 'ai.hosted.model'],
        'ai.api_key' => ['section' => 'ia', 'config' => 'ai.hosted.api_key'],
        'ai.connect_timeout' => ['section' => 'ia', 'config' => 'ai.hosted.connect_timeout_seconds'],
        'ai.timeout' => ['section' => 'ia', 'config' => 'ai.hosted.timeout_seconds'],
        'library.driver' => ['section' => 'biblioteca', 'config' => 'catalog.driver'],
        'library.base_url' => ['section' => 'biblioteca', 'config' => 'catalog.koha.base_url'],
    ];

    /** @var array<string, mixed>|null Valores del entorno, antes de aplicar lo guardado. */
    private ?array $environment = null;

    /** @var list<string> */
    private array $saved = [];

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /** Toma los valores actuales de la configuración como los del entorno (al arrancar, o en pruebas). */
    public function rememberEnvironment(): void
    {
        $this->environment = [];
        foreach (self::FIELDS as $field => $definition) {
            $this->environment[$field] = config($definition['config']);
        }
    }

    /** Aplica lo guardado sobre la configuración. Se llama al arrancar y antes de cada tarea en cola. */
    public function apply(): void
    {
        if ($this->environment === null) {
            $this->rememberEnvironment();
        }
        $stored = $this->stored();
        $this->saved = array_keys($stored);
        foreach (self::FIELDS as $field => $definition) {
            config([$definition['config'] => array_key_exists($field, $stored) ? $stored[$field] : ($this->environment[$field] ?? null)]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function describe(): array
    {
        $sections = ['notificaciones' => 'notifications', 'ia' => 'ai', 'biblioteca' => 'library'];
        $result = ['notifications' => [], 'ai' => [], 'library' => []];
        foreach (self::FIELDS as $field => $definition) {
            $name = substr($field, strpos($field, '.') + 1);
            $source = in_array($field, $this->saved, true) ? 'pantalla' : 'entorno';
            $value = config($definition['config']);
            $result[$sections[$definition['section']]][$name] = $field === self::SECRET
                ? ['configured' => is_string($value) && $value !== '', 'source' => $source]
                : ['value' => $value, 'source' => $source];
        }

        return $result;
    }

    /**
     * Guarda los ajustes de una sección. `ai.api_key` ausente o vacío conserva la clave; `null` la borra.
     *
     * @param  array<string, mixed>  $values  campo => valor, solo de la sección
     */
    public function save(string $section, array $values, User $actor, ?string $roleAssignmentId): void
    {
        $changed = [];
        DB::transaction(function () use ($section, $values, $actor, &$changed): void {
            foreach ($values as $field => $value) {
                if ((self::FIELDS[$field]['section'] ?? null) !== $section) {
                    continue;
                }
                $current = config(self::FIELDS[$field]['config']);
                if ($current !== $value || ! in_array($field, $this->saved, true)) {
                    $changed[] = $field;
                }
                $stored = $field === self::SECRET && is_string($value) ? Crypt::encryptString($value) : $value;
                DB::table('ajustes_sistema')->upsert([[
                    'clave_ajuste' => $field,
                    'valor_ajuste' => json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'modificado_por' => $actor->id,
                    'modificado_en' => now(),
                ]], ['clave_ajuste'], ['valor_ajuste', 'modificado_por', 'modificado_en']);
            }
        });
        $this->apply();

        $this->audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $roleAssignmentId,
            action: "ajustes.{$section}.actualizados",
            resourceType: 'ajustes_sistema',
            resourceId: null,
            result: 'exito',
            metadata: ['campos' => implode(', ', array_map(fn (string $field): string => substr($field, strpos($field, '.') + 1), $changed))],
        );
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        try {
            $rows = DB::table('ajustes_sistema')->pluck('valor_ajuste', 'clave_ajuste')->all();
        } catch (Throwable) {
            return [];
        }
        $values = [];
        foreach ($rows as $field => $json) {
            if (! is_string($field) || ! array_key_exists($field, self::FIELDS)) {
                continue;
            }
            $value = json_decode((string) $json, true);
            if ($field === self::SECRET && is_string($value)) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (DecryptException) {
                    // APP_KEY cambió: la clave guardada ya no se puede leer y hay que escribirla de nuevo.
                    Log::warning('La clave de la IA guardada no se pudo descifrar; vuelva a escribirla en Configuración › Sistema.');
                    $value = null;
                }
            }
            $values[$field] = $value;
        }

        return $values;
    }
}
