<?php

declare(strict_types=1);

namespace NoddaClient;

/**
 * Abstrae el transporte HTTP -- misma razon que AeatSoapClientInterface en
 * el Core Engine: sin esto, no se puede probar NoddaClient con tests
 * reales, porque no habria forma de sustituir la conexion real por un
 * doble de prueba.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function request(string $metodo, string $url, array $headers = [], ?string $cuerpo = null): array;
}
