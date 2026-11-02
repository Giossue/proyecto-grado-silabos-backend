<?php

namespace App\Modules\Operations\Application;

use App\Modules\Library\Domain\Contracts\BibliographicCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/** «Probar conexión» de Configuración › Sistema, con la configuración ya guardada. */
final class SystemSettingsConnection
{
    /** @return array{ok: bool, message: string} */
    public function ai(): array
    {
        $driver = strtolower(trim((string) config('ai.driver')));
        if (! in_array($driver, ['deepseek', 'openai', 'anthropic', 'claude'], true)) {
            return ['ok' => false, 'message' => 'El asistente de IA está desactivado'];
        }
        $key = trim((string) config('ai.hosted.api_key'));
        if ($key === '') {
            return ['ok' => false, 'message' => 'Falta la clave del proveedor de IA'];
        }
        $base = rtrim(trim((string) config('ai.hosted.base_url')), '/');
        if (! str_starts_with($base, 'https://')) {
            return ['ok' => false, 'message' => 'La dirección del proveedor debe empezar con https://'];
        }
        $headers = in_array($driver, ['anthropic', 'claude'], true)
            ? ['x-api-key' => $key, 'anthropic-version' => '2023-06-01']
            : ['Authorization' => 'Bearer '.$key];

        try {
            $response = Http::acceptJson()->withHeaders($headers)->withoutRedirecting()
                ->connectTimeout((int) config('ai.hosted.connect_timeout_seconds'))
                ->timeout(max(5, min(30, (int) config('ai.hosted.timeout_seconds'))))
                ->get($base.'/models');
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'No se pudo conectar con el proveedor de IA'];
        }

        return match (true) {
            $response->successful() => ['ok' => true, 'message' => 'Conexión correcta con el proveedor de IA'],
            in_array($response->status(), [401, 403], true) => ['ok' => false, 'message' => 'El proveedor no aceptó la clave'],
            default => ['ok' => false, 'message' => "El proveedor de IA respondió con un error ({$response->status()})"],
        };
    }

    /** @return array{ok: bool, message: string} */
    public function library(): array
    {
        if (strtolower(trim((string) config('catalog.driver'))) !== 'koha') {
            return ['ok' => false, 'message' => 'La búsqueda en la biblioteca está desactivada'];
        }
        try {
            $count = count(app(BibliographicCatalog::class)->libraries());
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'No se pudo consultar el catálogo de la biblioteca'];
        }

        return ['ok' => true, 'message' => $count === 1 ? 'Conexión correcta: 1 biblioteca' : "Conexión correcta: {$count} bibliotecas"];
    }
}
