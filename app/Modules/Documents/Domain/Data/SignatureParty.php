<?php

namespace App\Modules\Documents\Domain\Data;

/**
 * Una columna del bloque "Revisión y aprobación": la celda de firma queda vacía para
 * que la persona firme electrónicamente el documento exportado (FirmaEC).
 */
final readonly class SignatureParty
{
    public function __construct(
        public string $name,
        public string $role,
        public string $dateLabel,
        public string $date,
    ) {}
}
