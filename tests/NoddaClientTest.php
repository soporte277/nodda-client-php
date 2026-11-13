<?php

declare(strict_types=1);

namespace NoddaClient\Tests;

use PHPUnit\Framework\TestCase;
use NoddaClient\NoddaApiException;
use NoddaClient\NoddaClient;

final class NoddaClientTest extends TestCase
{
    public function testCrearFacturaMandaElMetodoYRutaCorrectos(): void
    {
        $fake = FakeHttpClient::queResponde(202, '{"id":"A-0001","status":"queued"}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY_DE_PRUEBA', $fake);

        $resultado = $client->crearFactura(['series' => 'A', 'number' => '0001']);

        $this->assertSame('A-0001', $resultado['id']);
        $this->assertSame('queued', $resultado['status']);
        $this->assertCount(1, $fake->peticionesRecibidas);
        [$metodo, $url, $headers, $cuerpo] = $fake->peticionesRecibidas[0];
        $this->assertSame('POST', $metodo);
        $this->assertSame('https://api.nodda.test/v1/invoices', $url);
        $this->assertSame('Bearer API_KEY_DE_PRUEBA', $headers['Authorization']);
        $this->assertJsonStringEqualsJsonString('{"series":"A","number":"0001"}', $cuerpo);
    }

    public function testConsultarEstadoMandaGetALaRutaCorrecta(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"id":"A-0001","status":"sent"}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $resultado = $client->consultarEstado('A-0001');

        $this->assertSame('sent', $resultado['status']);
        [$metodo, $url] = $fake->peticionesRecibidas[0];
        $this->assertSame('GET', $metodo);
        $this->assertSame('https://api.nodda.test/v1/invoices/A-0001', $url);
    }

    public function testCancelarFacturaMandaPostALaRutaDeCancel(): void
    {
        $fake = FakeHttpClient::queResponde(202, '{"id":"A-0001","status":"queued"}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->cancelarFactura('A-0001', ['generatedBy' => 'E']);

        [$metodo, $url] = $fake->peticionesRecibidas[0];
        $this->assertSame('POST', $metodo);
        $this->assertSame('https://api.nodda.test/v1/invoices/A-0001/cancel', $url);
    }

    public function testObtenerUrlQrPideFormatoUrlYDevuelveSoloLaUrl(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"url":"https://www2.agenciatributaria.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345678"}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $url = $client->obtenerUrlQr('A-0001');

        $this->assertSame('https://www2.agenciatributaria.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345678', $url);
        [, $urlPedida] = $fake->peticionesRecibidas[0];
        $this->assertStringContainsString('format=url', $urlPedida);
    }

    public function testObtenerImagenQrDevuelveElBinarioCrudoSinDecodificarComoJson(): void
    {
        $bytesFalsosDeUnPng = "\x89PNG-datos-binarios-de-prueba";
        $fake = FakeHttpClient::queResponde(200, $bytesFalsosDeUnPng);
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $imagen = $client->obtenerImagenQr('A-0001');

        $this->assertSame($bytesFalsosDeUnPng, $imagen);
    }

    public function testObtenerImagenQrConErrorLanzaNoddaApiException(): void
    {
        $fake = FakeHttpClient::queResponde(404, '{"error":{"code":"not_found","message":"No existe esa factura"}}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        try {
            $client->obtenerImagenQr('NUNCA-EXISTIO');
            $this->fail('Deberia haber lanzado NoddaApiException.');
        } catch (NoddaApiException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertSame('not_found', $e->codigoError);
        }
    }

    public function testEstadoSistemaMandaGetAStatus(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"canSendNow":true,"nextAllowedSendAt":null}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $resultado = $client->estadoSistema();

        $this->assertTrue($resultado['canSendNow']);
        [, $url] = $fake->peticionesRecibidas[0];
        $this->assertSame('https://api.nodda.test/v1/status', $url);
    }

    public function testListarFacturasSinFiltrosMandaGetSinQuery(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"invoices":[],"pagination":{"total":0,"limit":20,"offset":0}}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->listarFacturas();

        [$metodo, $url] = $fake->peticionesRecibidas[0];
        $this->assertSame('GET', $metodo);
        $this->assertSame('https://api.nodda.test/v1/invoices', $url);
    }

    public function testListarFacturasConFiltrosLosIncluyeEnLaQuery(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"invoices":[],"pagination":{"total":0,"limit":20,"offset":0}}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->listarFacturas(['status' => 'permanently_failed', 'limit' => '5']);

        [, $url] = $fake->peticionesRecibidas[0];
        $this->assertStringContainsString('status=permanently_failed', $url);
        $this->assertStringContainsString('limit=5', $url);
    }

    public function testReintentarFacturaSinTipoMandaPostSinQuery(): void
    {
        $fake = FakeHttpClient::queResponde(202, '{"id":"A-0001","status":"queued"}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->reintentarFactura('A-0001');

        [$metodo, $url] = $fake->peticionesRecibidas[0];
        $this->assertSame('POST', $metodo);
        $this->assertSame('https://api.nodda.test/v1/invoices/A-0001/retry', $url);
    }

    public function testReintentarFacturaConTipoLoIncluyeEnLaQuery(): void
    {
        $fake = FakeHttpClient::queResponde(202, '{"id":"A-0001","status":"queued"}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->reintentarFactura('A-0001', 'anulacion');

        [, $url] = $fake->peticionesRecibidas[0];
        $this->assertSame('https://api.nodda.test/v1/invoices/A-0001/retry?type=anulacion', $url);
    }

    public function testObtenerPerfilMandaGetALaRutaCorrecta(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"nifs":[{"nif":"B12345678","nombreEmisor":"Empresa Demo SL"}]}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $resultado = $client->obtenerPerfil();

        $this->assertSame('B12345678', $resultado['nifs'][0]['nif']);
        [$metodo, $url] = $fake->peticionesRecibidas[0];
        $this->assertSame('GET', $metodo);
        $this->assertSame('https://api.nodda.test/v1/client', $url);
    }

    /**
     * CAMBIO (multi-NIF, 9 de septiembre): un token puede autorizar
     * varios NIF -- estos metodos confirman que el parametro opcional
     * "nif" viaje correctamente (en el cuerpo para POST, en la URL
     * para GET), y que seguir sin pasarlo (token de 1 solo NIF) no
     * cambie nada del comportamiento anterior.
     */
    public function testCrearFacturaConNifLoIncluyeEnElCuerpo(): void
    {
        $fake = FakeHttpClient::queResponde(202, '{"id":"A-0001","status":"queued"}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->crearFactura(['series' => 'A', 'number' => '0001'], 'B22222222');

        [, , , $cuerpo] = $fake->peticionesRecibidas[0];
        $this->assertJsonStringEqualsJsonString('{"series":"A","number":"0001","nif":"B22222222"}', $cuerpo);
    }

    public function testCrearFacturaSinNifNoLoIncluyeEnElCuerpo(): void
    {
        $fake = FakeHttpClient::queResponde(202, '{"id":"A-0001","status":"queued"}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->crearFactura(['series' => 'A', 'number' => '0001']);

        [, , , $cuerpo] = $fake->peticionesRecibidas[0];
        $this->assertStringNotContainsString('nif', $cuerpo);
    }

    public function testListarFacturasConNifLoIncluyeEnLaUrl(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"invoices":[],"pagination":{"total":0,"limit":20,"offset":0}}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->listarFacturas(['status' => 'permanently_failed'], 'B33333333');

        [, $url] = $fake->peticionesRecibidas[0];
        $this->assertStringContainsString('nif=B33333333', $url);
        $this->assertStringContainsString('status=permanently_failed', $url);
    }

    public function testEstadoSistemaConNifLoIncluyeEnLaUrl(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"canSendNow":true,"nextAllowedSendAt":null}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->estadoSistema('B11111111');

        [, $url] = $fake->peticionesRecibidas[0];
        $this->assertSame('https://api.nodda.test/v1/status?nif=B11111111', $url);
    }

    public function testUnErrorDeLaApiLanzaNoddaApiExceptionConElDetalle(): void
    {
        $fake = FakeHttpClient::queResponde(422, '{"error":{"code":"business_rule_violation","message":"Fecha invalida"}}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        try {
            $client->crearFactura(['series' => 'A']);
            $this->fail('Deberia haber lanzado NoddaApiException.');
        } catch (NoddaApiException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertSame('business_rule_violation', $e->codigoError);
            $this->assertSame('Fecha invalida', $e->getMessage());
        }
    }

    public function testUnErrorSinCuerpoJsonValidoNoRompeElManejoDeErrores(): void
    {
        $fake = FakeHttpClient::queResponde(500, 'esto no es json');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        try {
            $client->consultarEstado('A-0001');
            $this->fail('Deberia haber lanzado NoddaApiException.');
        } catch (NoddaApiException $e) {
            $this->assertSame(500, $e->statusCode);
            $this->assertNull($e->codigoError);
        }
    }

    public function testCadaPeticionSeIdentificaConUserAgentPropio(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"canSendNow":true,"nextAllowedSendAt":null}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->estadoSistema();

        [, , $headers] = $fake->peticionesRecibidas[0];
        $this->assertMatchesRegularExpression('#^nodda-client-php/\d+\.\d+\.\d+ #', $headers['User-Agent']);
    }

    public function testLaApiKeyViajaEnElHeaderAuthorizationEnTodasLasPeticiones(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"canSendNow":true,"nextAllowedSendAt":null}');
        $client = new NoddaClient('https://api.nodda.test', 'MI_API_KEY_SECRETA', $fake);

        $client->estadoSistema();

        [, , $headers] = $fake->peticionesRecibidas[0];
        $this->assertSame('Bearer MI_API_KEY_SECRETA', $headers['Authorization']);
    }

    public function testCadaPeticionMandaUnCorrelationIdDistinto(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"canSendNow":true,"nextAllowedSendAt":null}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->estadoSistema();
        [, , $primerosHeaders] = $fake->peticionesRecibidas[0];

        $client->estadoSistema();
        [, , $segundosHeaders] = $fake->peticionesRecibidas[1];

        $this->assertArrayHasKey('X-Correlation-Id', $primerosHeaders);
        $this->assertNotSame(
            $primerosHeaders['X-Correlation-Id'],
            $segundosHeaders['X-Correlation-Id'],
            'Cada peticion debe llevar su propio correlation id, no reutilizar el mismo.'
        );
    }

    public function testUltimoCorrelationIdCoincideConElMandadoEnLaPeticion(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"canSendNow":true,"nextAllowedSendAt":null}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);

        $client->estadoSistema();
        [, , $headers] = $fake->peticionesRecibidas[0];

        $this->assertSame($headers['X-Correlation-Id'], $client->ultimoCorrelationId());
    }

    public function testConfigurarWebhookMandaPutConLaUrl(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"nif":"B1","url":"https://x.es/h","secreto":"abc"}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);
        $r = $client->configurarWebhook('https://x.es/h', 'B1');
        $this->assertSame('abc', $r['secreto']);
        [$metodo, $url, , $cuerpo] = $fake->peticionesRecibidas[0];
        $this->assertSame('PUT', $metodo);
        $this->assertSame('https://api.nodda.test/v1/webhooks?nif=B1', $url);
        $this->assertJsonStringEqualsJsonString('{"url":"https://x.es/h"}', $cuerpo);
    }

    public function testQuitarWebhookMandaDelete(): void
    {
        $fake = FakeHttpClient::queResponde(200, '{"nif":"B1","eliminado":true}');
        $client = new NoddaClient('https://api.nodda.test', 'API_KEY', $fake);
        $client->quitarWebhook();
        [$metodo, $url] = $fake->peticionesRecibidas[0];
        $this->assertSame('DELETE', $metodo);
        $this->assertSame('https://api.nodda.test/v1/webhooks', $url);
    }

    public function testVerificarFirmaWebhook(): void
    {
        $cuerpo = '{"id":"2026-0001","status":"sent"}';
        $this->assertTrue(NoddaClient::verificarFirmaWebhook($cuerpo, '17c2185e12eeb103aecf6c824b4719e2808bd202f743ad5c6109051803261fc8', 's3cr3t'));
        $this->assertTrue(NoddaClient::verificarFirmaWebhook($cuerpo, strtoupper('17c2185e12eeb103aecf6c824b4719e2808bd202f743ad5c6109051803261fc8'), 's3cr3t'));
        $this->assertFalse(NoddaClient::verificarFirmaWebhook($cuerpo . ' ', '17c2185e12eeb103aecf6c824b4719e2808bd202f743ad5c6109051803261fc8', 's3cr3t'));
        $this->assertFalse(NoddaClient::verificarFirmaWebhook($cuerpo, '17c2185e12eeb103aecf6c824b4719e2808bd202f743ad5c6109051803261fc8', 'otro'));
        $this->assertFalse(NoddaClient::verificarFirmaWebhook($cuerpo, null, 's3cr3t'));
    }
}
