# nodda/client (PHP)

Cliente oficial de la API de [Nodda](https://nodda.es) para emitir y consultar facturas **VERI\*FACTU** de la Agencia Tributaria (AEAT) desde tu propio programa (ERP, tienda online, TPV…).

Nodda se encarga de la firma con tu certificado, del XML, de la cadena de huellas y del envío a la AEAT: tú solo mandas los datos de la factura en JSON.

## Instalación

```bash
composer require nodda/client
```

Requisitos: PHP 8.1 o superior con las extensiones curl y json.

## Antes de empezar

1. Crea tu cuenta en el portal de Nodda y da de alta tu empresa (NIF y certificado digital).
2. Genera tu **clave API** en el portal → *API keys*.
3. Usa la dirección del entorno con su clave:

| Entorno | Dirección | Uso |
|---|---|---|
| Pruebas | `https://sandbox.nodda.es` | Envía al entorno de pruebas de la AEAT, sin validez fiscal |
| Producción | `https://api.nodda.es` | Facturas reales |

La clave de pruebas no sirve en producción, ni al revés.

## Uso

```php
use NoddaClient\NoddaClient;

$nodda = new NoddaClient('https://sandbox.nodda.es', getenv('NODDA_API_KEY'));

$r = $nodda->crearFactura([
    'series' => '2026', 'issueDate' => '2026-11-03', 'invoiceType' => 'F1',
    'description' => 'Servicios de consultoría', 'taxRegimeKey' => '01', 'operationQualification' => 'S1',
    'taxBase' => '100.00', 'taxRate' => '21', 'taxAmount' => '21.00', 'totalAmount' => '121.00',
    'recipients' => [['nif' => 'B12345674', 'name' => 'Cliente Demo SL']],
]);
// $r['status'] === 'queued': la API responde al momento; la AEAT, unos segundos después.

$estado = $nodda->consultarEstado($r['id']); // queued, sent, accepted_with_errors o permanently_failed
```

Si tu clave da acceso a varias empresas (NIF), indica cuál en el parámetro `nif` de cada método. Puedes asociar la factura a un centro de coste enviando `costCenterId` en los datos.

## Métodos

| Método | Endpoint |
|---|---|
| `crearFactura($datos, $nif = null)` | `POST /v1/invoices` |
| `consultarEstado($id, $nif = null)` | `GET /v1/invoices/{id}` |
| `listarFacturas($filtros = null, $nif = null)` | `GET /v1/invoices` |
| `cancelarFactura($id, $datos = [], $nif = null)` | `POST /v1/invoices/{id}/cancel` |
| `reintentarFactura($id, $tipo = null, $nif = null)` | `POST /v1/invoices/{id}/retry` |
| `obtenerUrlQr($id)` / `obtenerImagenQr($id)` | `GET /v1/invoices/{id}/qr` |
| `estadoSistema($nif = null)` | `GET /v1/status` |
| `obtenerPerfil()` | `GET /v1/client` |
| `configurarWebhook($url, $nif = null)` / `quitarWebhook($nif = null)` | `PUT` / `DELETE /v1/webhooks` |
| `NoddaClient::verificarFirmaWebhook($cuerpo, $firma, $secreto)` | Comprueba la firma de un aviso |

## Avisos (webhooks)

En lugar de consultar el estado de cada factura, Nodda puede avisar a tu servidor cuando la AEAT responde. Configura la URL con `configurarWebhook($url)` (la respuesta trae el `secreto` **una sola vez**: guárdalo) y, en tu endpoint, comprueba la firma antes de confiar en el aviso:

```php
$cuerpo = file_get_contents('php://input');
if (!NoddaClient::verificarFirmaWebhook($cuerpo, $_SERVER['HTTP_X_NODDA_SIGNATURE'] ?? null, getenv('NODDA_WEBHOOK_SECRET'))) {
    http_response_code(401);
    exit;
}
```

El cuerpo trae `id` (número de factura), `status` (`sent`, `accepted_with_errors` o `permanently_failed`), `razon`, `eventId` y los datos del acuse de la AEAT. Responde con un código 2xx; si no, Nodda lo reintenta.

## Errores

Si la API responde con un error, se lanza `NoddaApiException` con el código HTTP y un código estable (por ejemplo `invalid_request`, `business_rule_violation`, `certificate_not_configured`). Si no hubo respuesta (fallo de red), se lanza `NoddaException`: puedes reintentar la misma petición.

## Documentación

Guía completa, campos de cada petición y códigos de error: <https://app.nodda.es/developers/introduccion>
Referencia OpenAPI: <https://api.nodda.es/openapi.json>

Soporte: soporte@nodda.es

## Licencia

MIT
