<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NoddaClient\NoddaClient;

// Configuracion por variables de entorno (por defecto: sandbox).
//   NODDA_API_KEY  tu API key (obligatoria)
//   NODDA_URL      URL de la API (por defecto https://sandbox.nodda.es)
$url = getenv('NODDA_URL') ?: 'https://sandbox.nodda.es';
$apiKey = getenv('NODDA_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "Falta la variable de entorno NODDA_API_KEY.\n");
    exit(1);
}

$client = new NoddaClient($url, $apiKey);

// Numero unico por ejecucion para poder repetir el ejemplo sin duplicados.
$serie = 'EJ';
$numero = (string) time();
$id = $serie . '-' . $numero;

echo "=== crearFactura() ===\n";
$resultado = $client->crearFactura([
    'series' => $serie,
    'number' => $numero,
    'issueDate' => gmdate('Y-m-d'),
    'invoiceType' => 'F1',
    'description' => 'Prueba desde PHP',
    'taxRegimeKey' => '01',
    'operationQualification' => 'S1',
    'taxBase' => '100.00',
    'taxRate' => '21',
    'taxAmount' => '21.00',
    'totalAmount' => '121.00',
    'recipients' => [['nif' => 'B12345674', 'name' => 'Cliente Demo SL']],
]);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE) . "\n";

echo "=== consultarEstado() ===\n";
echo json_encode($client->consultarEstado($id), JSON_UNESCAPED_UNICODE) . "\n";

echo "=== obtenerUrlQr() ===\n";
echo json_encode($client->obtenerUrlQr($id), JSON_UNESCAPED_UNICODE) . "\n";

echo "=== estadoSistema() ===\n";
echo json_encode($client->estadoSistema(), JSON_UNESCAPED_UNICODE) . "\n";

// Anular solo es posible cuando la AEAT ya registro la factura.
echo "=== cancelarFactura() ===\n";
try {
    echo json_encode($client->cancelarFactura($id, []), JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo '(aun no se puede anular: ' . $e->getMessage() . ")\n";
}

echo "\nTODO CORRECTO\n";
