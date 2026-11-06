<?php

declare(strict_types=1);

namespace NoddaClient;

/**
 * Excepcion generica de comunicacion (red, timeout). Para errores que la
 * API devuelve con codigo HTTP de error (422, 404, etc.), ver
 * NoddaApiException -- son cosas distintas: esta es "no se pudo ni
 * hablar con la API", la otra es "la API respondio, pero con un error".
 */
class NoddaException extends \RuntimeException
{
}
