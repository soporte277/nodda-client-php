<?php

declare(strict_types=1);

namespace NoddaClient\Tests;

use NoddaClient\HttpClientInterface;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var array{0: string, 1: string, 2: array, 3: ?string}[] */
    public array $peticionesRecibidas = [];

    private int $statusAResponder = 200;
    private string $cuerpoAResponder = '{}';
    /** @var array<string, string> */
    private array $encabezadosAResponder = [];

    public static function queResponde(int $status, string $cuerpo): self
    {
        $fake = new self();
        $fake->statusAResponder = $status;
        $fake->cuerpoAResponder = $cuerpo;

        return $fake;
    }

    /**
     * AGREGADO (2026-09-25): para probar la lectura de encabezados de
     * respuesta (X-Nodda-Quota-Remaining) sin necesitar una conexion real.
     *
     * @param array<string, string> $encabezados
     */
    public static function queRespondeConEncabezados(int $status, string $cuerpo, array $encabezados): self
    {
        $fake = self::queResponde($status, $cuerpo);
        $fake->encabezadosAResponder = $encabezados;

        return $fake;
    }

    public function request(string $metodo, string $url, array $headers = [], ?string $cuerpo = null): array
    {
        $this->peticionesRecibidas[] = [$metodo, $url, $headers, $cuerpo];

        return ['status' => $this->statusAResponder, 'body' => $this->cuerpoAResponder, 'headers' => $this->encabezadosAResponder];
    }
}
