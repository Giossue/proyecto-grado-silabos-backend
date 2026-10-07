<?php

namespace App\Modules\Academic\Infrastructure\Gateways;

use App\Modules\Academic\Domain\Contracts\FichaExtractionGateway;
use RuntimeException;

final class DisabledFichaExtractionGateway implements FichaExtractionGateway
{
    public function available(): bool
    {
        return false;
    }

    public function propose(array $subject, array $fields, array $evidence): array
    {
        throw new RuntimeException('El asistente de IA no está configurado en este entorno.');
    }
}
