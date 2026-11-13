<?php

declare(strict_types=1);

namespace NoddaClient;

/**
 * SDK publico de Nodda -- capa 3 de la arquitectura:
 *
 *   Este SDK -> API propia -> Core Engine -> AEAT
 *
 * A proposito NO sabe nada de AEAT, certificados, XML, ni hash -- solo
 * habla HTTP/JSON con la API propia. Cualquier logica de negocio
 * (validaciones, reglas de VERI*FACTU) vive del lado de la API/Core
 * Engine, nunca aqui.
 *
 * CAMBIO (multi-NIF, 9 de septiembre): un token ahora puede autorizar
 * varios NIF (modelo "1 cuenta principal, varias empresas" -- ver
 * llamada de arranque del equipo). Por eso cada metodo que habla con
 * una factura especifica ahora acepta un $nif OPCIONAL -- si tu token
 * solo tiene 1 NIF autorizado, puedes seguir sin pasarlo, exactamente
 * como antes. Solo hace falta especificarlo si tu token tiene varios.
 */
final class NoddaClient
{
    /** Version del SDK; viaja en la cabecera User-Agent para que la API (y cualquier cortafuegos delante) identifique el cliente. */
    public const VERSION = '1.2.1';

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? new CurlHttpClient();
    }

    /**
     * POST /v1/invoices -- registra una factura. Siempre devuelve de
     * inmediato con status "queued" (la API nunca espera a AEAT) -- usa
     * consultarEstado() despues para saber el resultado real.
     *
     * @param array $datos Ver contrato_api_nodda.md para los campos exactos.
     * @param string|null $nif Solo necesario si tu token autoriza varios NIF.
     * @return array{id: string, status: string}
     */
    public function crearFactura(array $datos, ?string $nif = null): array
    {
        if ($nif !== null) {
            $datos['nif'] = $nif;
        }

        return $this->peticion('POST', '/v1/invoices', $datos);
    }

    /**
     * GET /v1/invoices/{id} -- consulta el estado real de una factura ya emitida.
     *
     * @return array{id: string, status: string, razon?: string, fecha?: string}
     */
    public function consultarEstado(string $id, ?string $nif = null): array
    {
        return $this->peticion('GET', "/v1/invoices/{$id}" . $this->queryNif($nif));
    }

    /**
     * POST /v1/invoices/{id}/cancel -- anula una factura. VERI*FACTU no
     * permite borrar, solo anular con un registro nuevo.
     *
     * @return array{id: string, status: string}
     */
    public function cancelarFactura(string $id, array $datos = [], ?string $nif = null): array
    {
        if ($nif !== null) {
            $datos['nif'] = $nif;
        }

        return $this->peticion('POST', "/v1/invoices/{$id}/cancel", $datos);
    }

    /** GET /v1/invoices/{id}/qr?format=url -- la URL del codigo QR de la factura. */
    public function obtenerUrlQr(string $id, ?string $nif = null): string
    {
        $query = $nif !== null ? "?format=url&nif=" . urlencode($nif) : '?format=url';
        $respuesta = $this->peticion('GET', "/v1/invoices/{$id}/qr{$query}");

        return (string)$respuesta['url'];
    }

    /**
     * GET /v1/invoices/{id}/qr -- la imagen PNG del codigo QR, en binario
     * crudo (listo para guardar a archivo o incrustar en un PDF).
     */
    public function obtenerImagenQr(string $id, ?string $nif = null): string
    {
        $respuesta = $this->http->request('GET', $this->urlCompleta("/v1/invoices/{$id}/qr" . $this->queryNif($nif)), $this->headers());

        if ($respuesta['status'] >= 400) {
            $this->lanzarErrorDesdeJson($respuesta);
        }

        return $respuesta['body'];
    }

    /**
     * GET /v1/status -- si se puede enviar ahora mismo, o hay que esperar
     * (control de flujo de AEAT). Util para quien orqueste envios masivos.
     *
     * NOTA (multi-NIF): el tiempo de espera de AEAT es por certificado,
     * no por token -- si tu token tiene varios NIF, especifica cual
     * quieres consultar. Si tu token solo tiene 1 NIF, no hace falta.
     *
     * @return array{canSendNow: bool, nextAllowedSendAt: ?string}
     */
    public function estadoSistema(?string $nif = null): array
    {
        return $this->peticion('GET', '/v1/status' . $this->queryNif($nif));
    }

    /**
     * GET /v1/invoices -- lista las facturas propias, con filtros
     * opcionales.
     *
     * @param array<string, string>|null $filtros Opcionales: status, type,
     *   from, to, limit, offset.
     * @param string|null $nif Solo necesario si tu token autoriza varios NIF.
     * @return array{invoices: array, pagination: array{total: int, limit: int, offset: int}}
     */
    public function listarFacturas(?array $filtros = null, ?string $nif = null): array
    {
        $filtros ??= [];
        if ($nif !== null) {
            $filtros['nif'] = $nif;
        }

        $query = $filtros !== [] ? '?' . http_build_query($filtros) : '';

        return $this->peticion('GET', "/v1/invoices{$query}");
    }

    /**
     * POST /v1/invoices/{id}/retry -- fuerza un reintento manual, sin
     * esperar el ciclo automatico.
     *
     * @param string|null $tipo 'alta' o 'anulacion', para desambiguar.
     * @param string|null $nif Solo necesario si tu token autoriza varios NIF.
     * @return array{id: string, status: string}
     */
    public function reintentarFactura(string $id, ?string $tipo = null, ?string $nif = null): array
    {
        $parametros = [];
        if ($tipo !== null) {
            $parametros['type'] = $tipo;
        }
        if ($nif !== null) {
            $parametros['nif'] = $nif;
        }
        $query = $parametros !== [] ? '?' . http_build_query($parametros) : '';

        return $this->peticion('POST', "/v1/invoices/{$id}/retry{$query}");
    }

    /**
     * GET /v1/client -- los NIF autorizados para este token.
     *
     * CAMBIO (multi-NIF): antes devolvia un solo {nif, nombreEmisor} --
     * ahora devuelve la LISTA completa de NIF que tu token puede usar.
     *
     * @return array{nifs: array<int, array{nif: string, nombreEmisor: string}>}
     */
    public function obtenerPerfil(): array
    {
        return $this->peticion('GET', '/v1/client');
    }

    /**
     * PUT /v1/webhooks -- configura la URL (https) a la que Nodda avisa cuando la AEAT responde a cada factura.
     * La respuesta trae el 'secreto' de firma UNA sola vez: guardalo para verificarFirmaWebhook().
     */
    public function configurarWebhook(string $url, ?string $nif = null): array
    {
        return $this->peticion('PUT', '/v1/webhooks' . $this->queryNif($nif), ['url' => $url]);
    }

    /** DELETE /v1/webhooks -- deja de enviar avisos. */
    public function quitarWebhook(?string $nif = null): array
    {
        return $this->peticion('DELETE', '/v1/webhooks' . $this->queryNif($nif));
    }

    /**
     * Comprueba que un aviso de webhook viene de Nodda: la cabecera X-Nodda-Signature es el HMAC-SHA256 (hex) del
     * cuerpo EXACTO recibido (file_get_contents('php://input')), calculado con tu secreto.
     */
    public static function verificarFirmaWebhook(string $cuerpo, ?string $firma, string $secreto): bool
    {
        if ($firma === null || $firma === '' || $secreto === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $cuerpo, $secreto), strtolower(trim($firma)));
    }

    /** Construye "?nif=..." si se paso un NIF, o cadena vacia si no. */
    private function queryNif(?string $nif): string
    {
        return $nif !== null ? '?nif=' . urlencode($nif) : '';
    }

    private function peticion(string $metodo, string $ruta, ?array $datos = null): array
    {
        $cuerpo = $datos !== null ? json_encode($datos) : null;
        $respuesta = $this->http->request($metodo, $this->urlCompleta($ruta), $this->headers(), $cuerpo);

        if ($respuesta['status'] >= 400) {
            $this->lanzarErrorDesdeJson($respuesta);
        }

        // AGREGADO (encontrado sin conectar en la revision del
        // 2026-09-25): antes, los encabezados de la respuesta se
        // descartaban por completo -- X-Nodda-Quota-Remaining nunca
        // llegaba a ningun lado, aunque el servidor si lo mandara.
        $this->ultimoCupoRestante = isset($respuesta['headers']['X-Nodda-Quota-Remaining'])
            ? (int) $respuesta['headers']['X-Nodda-Quota-Remaining']
            : null;

        $decodificado = json_decode($respuesta['body'], true);
        if (!is_array($decodificado)) {
            throw new NoddaException("La API devolvio una respuesta que no es JSON valido: {$respuesta['body']}");
        }

        return $decodificado;
    }

    /** @param array{status: int, body: string} $respuesta */
    private function lanzarErrorDesdeJson(array $respuesta): never
    {
        $decodificado = json_decode($respuesta['body'], true);
        $mensaje = $decodificado['error']['message'] ?? "La API respondio con el codigo HTTP {$respuesta['status']}.";
        $codigo = $decodificado['error']['code'] ?? null;

        throw new NoddaApiException($mensaje, $respuesta['status'], $codigo);
    }

    private function urlCompleta(string $ruta): string
    {
        return rtrim($this->baseUrl, '/') . $ruta;
    }

    private ?string $ultimoCorrelationId = null;

    public function ultimoCorrelationId(): ?string
    {
        return $this->ultimoCorrelationId;
    }

    /**
     * Cuantas facturas gratuitas le quedan al cliente, segun el ultimo
     * llamado a crearFactura() -- null si el NIF no esta sujeto a
     * cupo (plan de pago), o si el servidor no mando el encabezado
     * (versiones viejas de la API, o el NIF no esta en el sistema de
     * cuentas nuevo). No hace su propia peticion -- solo lee el
     * encabezado de la ULTIMA respuesta ya recibida.
     */
    private ?int $ultimoCupoRestante = null;

    public function ultimoCupoRestante(): ?int
    {
        return $this->ultimoCupoRestante;
    }

    /**
     * POST /v1/cuentas -- registro publico, SIN necesitar una API Key
     * previa (por eso no usa $this->peticion(), que siempre manda
     * Authorization). Devuelve una API Key nueva -- guardala de
     * inmediato, el servidor no la vuelve a mostrar.
     *
     * AGREGADO (encontrado sin exponer en el SDK, revision del
     * 2026-09-25) -- el endpoint ya existia en la API desde antes,
     * pero nadie podia usarlo sin salirse del SDK.
     *
     * @return array{cuentaId: string, nifId: string, apiKey: string, aviso: string}
     */
    public static function registrarCuenta(
        string $baseUrl,
        string $email,
        string $nif,
        string $nombreEmisor,
        ?string $telefono = null,
        ?HttpClientInterface $http = null,
    ): array {
        $http ??= new CurlHttpClient();
        $datos = ['email' => $email, 'nif' => $nif, 'nombreEmisor' => $nombreEmisor];
        if ($telefono !== null) {
            $datos['telefono'] = $telefono;
        }

        $respuesta = $http->request(
            'POST',
            rtrim($baseUrl, '/') . '/v1/cuentas',
            ['Content-Type' => 'application/json', 'User-Agent' => self::userAgent()],
            json_encode($datos),
        );

        if ($respuesta['status'] >= 400) {
            $decodificado = json_decode($respuesta['body'], true);
            $mensaje = $decodificado['error']['message'] ?? "La API respondio con el codigo HTTP {$respuesta['status']}.";
            $codigo = $decodificado['error']['code'] ?? null;
            throw new NoddaApiException($mensaje, $respuesta['status'], $codigo);
        }

        $decodificado = json_decode($respuesta['body'], true);
        if (!is_array($decodificado)) {
            throw new NoddaException("La API devolvio una respuesta que no es JSON valido: {$respuesta['body']}");
        }

        return $decodificado;
    }

    private function headers(): array
    {
        $this->ultimoCorrelationId = bin2hex(random_bytes(16));

        return [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'User-Agent' => self::userAgent(),
            'X-Correlation-Id' => $this->ultimoCorrelationId,
        ];
    }

    private static function userAgent(): string
    {
        return 'nodda-client-php/' . self::VERSION . ' (php ' . PHP_VERSION . ')';
    }
}
