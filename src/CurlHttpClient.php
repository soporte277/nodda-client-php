<?php

declare(strict_types=1);

namespace NoddaClient;

/**
 * Implementacion real usando la extension curl de PHP directamente --
 * sin Guzzle ni ninguna otra libreria HTTP, mismo criterio que ya se uso
 * en la API propia (Router propio en vez de Slim/Symfony): mantener el
 * SDK publico con el minimo de dependencias posible, ya que lo va a
 * instalar gente externa a la empresa.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function request(string $metodo, string $url, array $headers = [], ?string $cuerpo = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new NoddaException("No se pudo inicializar la conexion HTTP hacia: {$url}");
        }

        $headersFormateados = [];
        foreach ($headers as $nombre => $valor) {
            $headersFormateados[] = "{$nombre}: {$valor}";
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($metodo),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headersFormateados,
            CURLOPT_TIMEOUT => 30,
            // AGREGADO (encontrado sin usar en la revision del
            // 2026-09-25): sin esto, los encabezados de respuesta
            // (como X-Nodda-Quota-Remaining) nunca llegaban a existir
            // en ningun lado -- curl los descartaba por completo.
            CURLOPT_HEADER => true,
        ]);

        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }

        $respuestaCompleta = curl_exec($ch);
        if ($respuestaCompleta === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new NoddaException("Fallo de comunicacion con la API de Nodda: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tamanoEncabezados = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $textoEncabezados = substr($respuestaCompleta, 0, $tamanoEncabezados);
        $cuerpoRespuesta = substr($respuestaCompleta, $tamanoEncabezados);

        return [
            'status' => $status,
            'body' => $cuerpoRespuesta,
            'headers' => $this->parsearEncabezados($textoEncabezados),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parsearEncabezados(string $texto): array
    {
        $encabezados = [];
        // Con redirecciones, curl concatena los encabezados de CADA
        // salto -- solo nos interesa el ultimo bloque (la respuesta
        // final), por eso se parte por lineas en blanco y se usa el
        // ultimo bloque no vacio.
        $bloques = preg_split('/\r\n\r\n|\n\n/', trim($texto));
        $ultimoBloque = end($bloques);

        foreach (explode("\r\n", str_replace("\n", "\r\n", $ultimoBloque)) as $linea) {
            if (!str_contains($linea, ':')) {
                continue;
            }
            [$nombre, $valor] = explode(':', $linea, 2);
            // CORREGIDO (encontrado probando contra sandbox real,
            // 2026-09-25): HTTP/2 exige que los nombres de encabezado
            // vayan en minusculas (curl los devuelve tal cual,
            // "x-nodda-quota-remaining", no "X-Nodda-Quota-
            // Remaining"). Sin normalizar, la busqueda por clave
            // exacta en NoddaClient::peticion() nunca encontraba nada
            // -- el encabezado SI llegaba, solo que con otra
            // capitalizacion. Se normaliza aqui a Title-Case (un
            // formato, sin importar si la respuesta vino por HTTP/1.1
            // o HTTP/2), para que el resto del codigo pueda buscar
            // por un nombre fijo, consistente, sin este problema.
            $nombreNormalizado = str_replace(' ', '-', ucwords(str_replace('-', ' ', trim($nombre))));
            $encabezados[$nombreNormalizado] = trim($valor);
        }

        return $encabezados;
    }
}
