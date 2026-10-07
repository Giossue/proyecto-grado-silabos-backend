<?php

namespace App\Modules\Academic\Domain\Contracts;

/**
 * Propone la ficha de UNA materia a partir de fragmentos de los documentos de la carrera.
 * Solo puede usar la evidencia recibida; lo que no esté sustentado se devuelve vacío.
 */
interface FichaExtractionGateway
{
    public function available(): bool;

    /**
     * @param  array{code: string, name: string, cycle: int|null}  $subject
     * @param  list<array{key: string, label: string, help: string|null}>  $fields  campos de la ficha que define la plantilla
     * @param  list<string>  $evidence  fragmentos de los documentos
     * @return array<string, string|list<string>> valor por clave de campo; cadena vacía o lista vacía si no hay sustento
     */
    public function propose(array $subject, array $fields, array $evidence): array;
}
