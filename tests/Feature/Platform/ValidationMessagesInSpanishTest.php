<?php

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/*
 * Ningún mensaje de validación que ve el usuario puede exponer nombres técnicos de
 * campos (`properties.0.label`, `teacher_user_id`) ni frases en inglés. Se recorren
 * todas las FormRequest y se validan con datos vacíos e inválidos.
 */

/** Claves de una palabra que ya se leen bien en español. */
const ATRIBUTOS_YA_EN_ESPANOL = ['nombre', 'activo', 'estado', 'carrera', 'periodo', 'fecha', 'numero', 'campus', 'facultad', 'creditos'];

/** @return list<class-string<FormRequest>> */
function formRequestsDelSistema(): array
{
    $clases = [];
    foreach (['app/Modules', 'app/Http/Requests'] as $raiz) {
        $ruta = base_path($raiz);
        if (! is_dir($ruta)) {
            continue;
        }
        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ruta));
        foreach ($iterador as $archivo) {
            if (! $archivo->isFile() || ! str_ends_with($archivo->getFilename(), 'Request.php')) {
                continue;
            }
            $relativa = Str::after($archivo->getPathname(), base_path('app').DIRECTORY_SEPARATOR);
            $clase = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $relativa);
            if (class_exists($clase) && is_subclass_of($clase, FormRequest::class)
                && ! (new ReflectionClass($clase))->isAbstract()) {
                $clases[] = $clase;
            }
        }
    }
    sort($clases);

    return $clases;
}

/** @param array<string, mixed> $reglas */
function datosInvalidosPara(array $reglas): array
{
    $datos = [];
    foreach (array_keys($reglas) as $clave) {
        // Cada hoja recibe un valor de tipo equivocado; los comodines se vuelven índice 0.
        Arr::set($datos, str_replace('*', '0', $clave), ['__invalido__' => true]);
    }

    return $datos;
}

function mensajeTecnico(string $mensaje): ?string
{
    if (preg_match('/\b[a-z]+[a-z0-9]*(?:[._][a-z0-9]+)+\b/', $mensaje, $m)) {
        return $m[0];
    }
    if (preg_match('/\b(field|must|required|invalid|the|and \d+ more)\b/i', $mensaje, $m)) {
        return $m[0];
    }

    return null;
}

it('muestra mensajes de validación sin nombres técnicos ni inglés', function () {
    $problemas = [];
    $revisadas = 0;

    foreach (formRequestsDelSistema() as $clase) {
        try {
            /** @var FormRequest $solicitud */
            $solicitud = $clase::create('/', 'POST', []);
            $solicitud->setContainer(app());
            // Contexto mínimo: sesión y un usuario, como en una petición autenticada real.
            $solicitud->setLaravelSession(app('session.store'));
            $usuario = User::factory()->create();
            $solicitud->setUserResolver(fn () => $usuario);
            $reglas = method_exists($solicitud, 'rules') ? app()->call([$solicitud, 'rules']) : [];
        } catch (Throwable $e) {
            fwrite(STDERR, "OMITIDA {$clase}: {$e->getMessage()}\n");

            // Reglas que dependen de la ruta o del usuario autenticado: se omiten aquí.
            continue;
        }
        if ($reglas === []) {
            continue;
        }
        $revisadas++;

        $nombres = Validator::make([], $reglas, [], $solicitud->attributes());
        foreach (array_keys($reglas) as $clave) {
            if (str_contains($clave, '.') && ! str_contains($clave, '*')) {
                continue; // mensajes personalizados «campo.regla»
            }
            $concreta = str_replace('*', '0', $clave);
            $visible = $nombres->getDisplayableAttribute($concreta);
            if ($visible === str_replace('_', ' ', Str::snake($concreta)) && ! in_array($clave, ATRIBUTOS_YA_EN_ESPANOL, true)) {
                $problemas[] = class_basename($clase).": atributo sin nombre visible «{$clave}»";
            }
        }

        foreach ([[], datosInvalidosPara($reglas)] as $datos) {
            try {
                $validador = Validator::make($datos, $reglas, $solicitud->messages(), $solicitud->attributes());
                $mensajes = $validador->errors()->all();
            } catch (Throwable) {
                continue;
            }
            foreach ($mensajes as $mensaje) {
                if (($hallazgo = mensajeTecnico($mensaje)) !== null) {
                    $problemas[] = class_basename($clase).": «{$mensaje}» ({$hallazgo})";
                }
            }
        }
    }

    expect($revisadas)->toBeGreaterThan(30)
        ->and(array_values(array_unique($problemas)))->toBe([]);
});
