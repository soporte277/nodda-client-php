<?php

declare(strict_types=1);

namespace NoddaClient;

/**
 * Se lanza cuando la API de Nodda SI respondio, pero con un codigo
 * de error (422 = dato invalido o regla de negocio, 404 = no encontrado,
 * 500 = error interno). Distinta de NoddaException (esa es cuando
 * ni siquiera hubo respuesta -- problema de red).
 */
final class NoddaApiException extends NoddaException
{
    public function __construct(
        string $mensaje,
        public readonly int $statusCode,
        public readonly ?string $codigoError,
    ) {
        parent::__construct($mensaje);
    }
}
