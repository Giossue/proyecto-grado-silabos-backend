<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Academic\Application\CurricularData;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Parte que «sale sola de los documentos de la carrera» (I-151). Administración elige un
 * dato curricular ya declarado o declara uno nuevo; la parte se guarda como texto fijo
 * con ese dato automático. Coordinación lo llena después mediante importación.
 */
final class AutomaticPart
{
    /**
     * @param  array<string, mixed>  $data  `concept_key` de un dato declarado o `concept` {label, type, scope}.
     * @return array{concept: CurricularConcept, declared: bool}
     */
    public static function concept(array $data, string $errorKey = 'concept'): array
    {
        $key = $data['concept_key'] ?? null;
        $new = $data['concept'] ?? null;
        if (is_string($key) && $key !== '') {
            $concept = CurricularConcept::query()->where('clave_concepto', $key)->first();
            if ($concept === null) {
                throw ValidationException::withMessages([$errorKey => 'El dato elegido ya no existe. Recargue la plantilla.']);
            }

            return ['concept' => $concept, 'declared' => false];
        }
        if (! is_array($new) || ! is_string($new['label'] ?? null) || trim($new['label']) === '') {
            throw ValidationException::withMessages([$errorKey => 'Elija un dato de los documentos o escriba el nombre del dato nuevo.']);
        }
        $label = trim($new['label']);
        $type = in_array($new['type'] ?? null, ['texto', 'lista'], true) ? $new['type'] : 'lista';
        $scope = in_array($new['scope'] ?? null, ['asignatura', 'carrera'], true) ? $new['scope'] : 'asignatura';
        $conceptKey = self::keyFor($label);
        $concept = CurricularConcept::query()->where('clave_concepto', $conceptKey)->first();
        if ($concept !== null) {
            if ($concept->tipo_concepto !== $type || $concept->ambito_concepto !== $scope) {
                throw ValidationException::withMessages([$errorKey => "Ya existe el dato «{$concept->etiqueta_concepto}» con otra forma. Elíjalo de la lista o use otro nombre."]);
            }

            return ['concept' => $concept, 'declared' => false];
        }
        $concept = CurricularConcept::query()->create([
            'clave_concepto' => $conceptKey, 'etiqueta_concepto' => $label,
            'tipo_concepto' => $type, 'ambito_concepto' => $scope, 'columnas_concepto' => null,
        ]);

        return ['concept' => $concept, 'declared' => true];
    }

    /** @return array<string, mixed> Documento de la parte: el dato automático como párrafo. */
    public static function document(CurricularConcept $concept): array
    {
        $variable = CurricularData::VARIABLE_PREFIX.$concept->clave_concepto;

        return ['type' => 'doc', 'content' => [[
            'type' => 'paragraph', 'attrs' => ['textAlign' => 'left'],
            'content' => [['type' => 'variable', 'attrs' => ['id' => $variable, 'label' => $variable], 'marks' => []]],
        ]]];
    }

    /** Clave estable del dato: el nombre en minúsculas, sin tildes y con guiones bajos. */
    public static function keyFor(string $label): string
    {
        $key = Str::limit(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(Str::ascii($label))), '_'), 90, '');
        $key = rtrim($key, '_');

        return preg_match('/^[a-z]/', $key) === 1 ? $key : 'dato_'.$key;
    }
}
