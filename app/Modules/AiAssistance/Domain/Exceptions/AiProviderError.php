<?php

namespace App\Modules\AiAssistance\Domain\Exceptions;

use Throwable;

class AiProviderError extends AiGatewayUnavailable
{
    public const NO_BALANCE = 'sin_saldo';

    public const INVALID_KEY = 'clave_invalida';

    public const BUSY = 'ocupado';

    public const REJECTED = 'solicitud_rechazada';

    public const UNAVAILABLE = 'no_disponible';

    public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
