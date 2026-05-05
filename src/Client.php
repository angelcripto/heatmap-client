<?php

declare(strict_types=1);

namespace ElBunkerBitcoin\HeatmapClient;

use InvalidArgumentException;

/**
 * Cliente oficial para firmar requests al heatmap CDN de El Búnker Bitcoin.
 *
 * Uso típico desde el backend de un cliente externo:
 *
 *     // 1. En el .env:
 *     //    BUNKER_LICENSE_KEY=...
 *     //    BUNKER_HMAC_SECRET=...
 *     //
 *     // 2. En la ruta de tu backend que el browser llama, ej. /heatmap-sign:
 *     $client = new \ElBunkerBitcoin\HeatmapClient\Client(
 *         getenv('BUNKER_LICENSE_KEY'),
 *         getenv('BUNKER_HMAC_SECRET')
 *     );
 *     $signed = $client->signRequest($_GET['path']);
 *     header('Content-Type: application/json');
 *     echo $signed->toJson();
 *
 *     // 3. En tu HTML:
 *     // <script src="https://elbunkerbitcoin.com/cdn/bunker-heatmap.js"></script>
 *     // <script>
 *     //   new BunkerHeatmap({
 *     //     mount: '#mi-heatmap',
 *     //     signEndpoint: '/heatmap-sign',
 *     //     symbol: 'BTCUSDT'
 *     //   });
 *     // </script>
 *
 * Cómo funciona la firma:
 *   - Cada request lleva en el querystring: _bk_lic, _bk_ts, _bk_nonce, _bk_sig
 *   - _bk_sig = HMAC-SHA256(secret, ts.nonce.METHOD.path_sin_los_4_params.body)
 *   - El server valida ts dentro de ±60s, nonce único en 5min, firma constant-time,
 *     origin del request en allowed_origins de la license, y rate limit.
 *   - El secret nunca cruza al browser. El browser solo ve la URL ya firmada,
 *     que caduca en 60s y no puede reutilizarse (nonce único).
 */
final class Client
{
    /** Path prefix que aceptamos firmar. No firmamos paths arbitrarios. */
    private const ALLOWED_PATH_PREFIX = '/api/heatmap/';

    /** Default base URL del Búnker. Sobrescribir solo en testing. */
    public const DEFAULT_BASE_URL = 'https://elbunkerbitcoin.com';

    /** Tamaño en bytes del nonce (32 hex chars). */
    private const NONCE_BYTES = 16;

    /** @var string license_key pública (visible en URLs). */
    private string $licenseKey;

    /** @var string hmac_secret privado. NUNCA debe salir del backend. */
    private string $hmacSecret;

    /** @var string base URL sin trailing slash. */
    private string $baseUrl;

    /**
     * @param string $licenseKey  La license_key que te dio El Búnker.
     * @param string $hmacSecret  El hmac_secret. CARGA DESDE .env, no lo hardcodees.
     * @param string $baseUrl     Por defecto https://elbunkerbitcoin.com.
     *
     * @throws InvalidArgumentException si las credenciales están vacías o tienen formato sospechoso.
     */
    public function __construct(string $licenseKey, string $hmacSecret, string $baseUrl = self::DEFAULT_BASE_URL)
    {
        $licenseKey = trim($licenseKey);
        $hmacSecret = trim($hmacSecret);

        if ($licenseKey === '') {
            throw new InvalidArgumentException('licenseKey no puede estar vacía. Cárgala desde tu .env (variable BUNKER_LICENSE_KEY o equivalente).');
        }
        if ($hmacSecret === '') {
            throw new InvalidArgumentException('hmacSecret no puede estar vacío. Cárgalo desde tu .env (variable BUNKER_HMAC_SECRET o equivalente).');
        }
        // Sanity check: las credenciales del Búnker son hex. Si lo que recibimos
        // no parece hex, probablemente el cliente las pasó cruzadas o concatenadas
        // con espacios. Avisamos pronto.
        if (!ctype_xdigit($licenseKey) || strlen($licenseKey) < 32) {
            throw new InvalidArgumentException('licenseKey con formato inesperado. Debería ser una cadena hex de al menos 32 caracteres.');
        }
        if (!ctype_xdigit($hmacSecret) || strlen($hmacSecret) < 64) {
            throw new InvalidArgumentException('hmacSecret con formato inesperado. Debería ser una cadena hex de al menos 64 caracteres.');
        }

        $this->licenseKey = $licenseKey;
        $this->hmacSecret = $hmacSecret;
        $this->baseUrl    = rtrim($baseUrl, '/');
    }

    /**
     * Firma una request al heatmap CDN y devuelve un objeto SignedRequest con
     * la URL completa lista para que el browser la fetchee directamente.
     *
     * @param string $path  Path con querystring, ej. "/api/heatmap/oi-history?symbol=BTCUSDT&interval=5m".
     *                      DEBE empezar por "/api/heatmap/". El cliente JS
     *                      pasa esto desde signEndpoint.
     * @param string $method  GET, POST, etc. Default GET (el heatmap solo usa GETs ahora mismo).
     * @param string $body    Body raw para POST/PUT. Vacío para GET.
     * @param int|null $bunkerUserId  ID del usuario en el Búnker que está
     *                      consumiendo el heatmap. Obligatorio si la license
     *                      tiene require_user_session=1 (caso típico: Omega
     *                      linkea sus users con cuentas del Búnker, y solo
     *                      los que pagan el plugin pueden ver el mapa). El
     *                      ID se incluye DENTRO del payload firmado, así no
     *                      puede manipularse desde el browser.
     *
     * @throws InvalidArgumentException si el path no empieza por /api/heatmap/.
     */
    public function signRequest(
        string $path,
        string $method = 'GET',
        string $body = '',
        ?int $bunkerUserId = null
    ): SignedRequest {
        if ($path === '' || strpos($path, self::ALLOWED_PATH_PREFIX) !== 0) {
            throw new InvalidArgumentException(
                'path debe empezar por "' . self::ALLOWED_PATH_PREFIX . '". Recibido: "' . $path . '". '
                . 'Esto previene que un cliente malicioso use tu firmador como "oracle" para firmar URLs arbitrarias del Búnker.'
            );
        }

        $method = strtoupper($method);
        $ts     = (string) time();
        $nonce  = bin2hex(random_bytes(self::NONCE_BYTES));

        // Si va bunkerUserId, lo añadimos al path ANTES de firmar. El server
        // lee _bk_user del querystring y lo incluye en el cleanPath que
        // reconstruye para verificar. Si alguien intercepta la URL final y
        // cambia _bk_user, la firma deja de validar.
        $pathToSign = $path;
        if ($bunkerUserId !== null && $bunkerUserId > 0) {
            $sep = (strpos($pathToSign, '?') === false) ? '?' : '&';
            $pathToSign .= $sep . '_bk_user=' . $bunkerUserId;
        }

        // Payload firmado: ts.nonce.METHOD.pathToSign.body
        // pathToSign INCLUYE _bk_user pero NO los 4 params de auth (los
        // añadimos después de firmar — si los incluyéramos sería circular).
        $payload = $ts . '.' . $nonce . '.' . $method . '.' . $pathToSign . '.' . $body;
        $sig     = hash_hmac('sha256', $payload, $this->hmacSecret);

        // Construimos la URL final: pathToSign (que ya tiene _bk_user) +
        // los 4 params auth. Soportamos paths que ya tengan ? o que no.
        $sep = (strpos($pathToSign, '?') === false) ? '?' : '&';
        $authQs = $sep
                . '_bk_lic='   . rawurlencode($this->licenseKey)
                . '&_bk_ts='   . rawurlencode($ts)
                . '&_bk_nonce='. rawurlencode($nonce)
                . '&_bk_sig='  . rawurlencode($sig);

        $signedUrl = $this->baseUrl . $pathToSign . $authQs;

        return new SignedRequest($signedUrl, (int) $ts, 60);
    }

    /**
     * Firma una URL en una sola línea. Útil para casos rápidos donde no
     * necesitas el objeto SignedRequest completo.
     *
     *     $url = (new Client($key, $secret))->getSignedUrl('/api/heatmap/...');
     */
    public function getSignedUrl(string $path, string $method = 'GET', string $body = '', ?int $bunkerUserId = null): string
    {
        return $this->signRequest($path, $method, $body, $bunkerUserId)->getUrl();
    }

    /**
     * Helper estático para flujos one-shot. NO usarlo si vas a firmar muchas
     * requests — instancia el Client una vez y reusa.
     */
    public static function quickSign(string $licenseKey, string $hmacSecret, string $path, ?int $bunkerUserId = null): string
    {
        return (new self($licenseKey, $hmacSecret))->getSignedUrl($path, 'GET', '', $bunkerUserId);
    }

    // ════════════════════════════════════════════════════════════════════════
    // ═══ MODO SERVER-TO-SERVER (S2S) ════════════════════════════════════════
    // ════════════════════════════════════════════════════════════════════════
    // Los métodos fetch* hacen la llamada HTTP directamente desde tu backend.
    // La IP que llega al Búnker es la fija de tu server, así que la license
    // tiene que tener tu IP en allowed_ips. NO se manda header Origin (cURL
    // no lo pone), por lo que el server detecta modo S2S y valida solo IP.
    //
    // Casos de uso típicos:
    //   - Cachear data del heatmap server-side y servirla a tus usuarios.
    //   - Procesar/agregar los datos antes de enseñarlos.
    //   - Generar reportes/exports/PDF con los datos del heatmap.
    //   - Alertas internas basadas en los datos sin tener un browser delante.
    //
    // Si lo que quieres es montar el embed JS en una página web, NO uses
    // fetch* — usa signRequest() y devuelve la URL al browser para que
    // haga el fetch él mismo (modo embed, valida origin no IP).

    /**
     * Hace una llamada server-to-server al heatmap CDN y devuelve la respuesta
     * JSON ya decodificada como array.
     *
     * @param string $path    Path con querystring, ej: "/api/heatmap/oi-history?symbol=BTCUSDT&interval=5m"
     * @param string $method  Default "GET" — el heatmap solo acepta GET ahora mismo
     * @param string $body    Body raw para POST/PUT (vacío para GET)
     * @param int $timeoutSec Timeout total de la request (default 15s)
     *
     * @return array  Respuesta del Búnker decodificada de JSON
     * @throws HeatmapException si la response no es 2xx o no es JSON válido
     * @throws \RuntimeException si la extensión cURL no está disponible
     */
    public function fetch(string $path, string $method = 'GET', string $body = '', int $timeoutSec = 15, ?int $bunkerUserId = null): array
    {
        $resp = $this->fetchResponse($path, $method, $body, $timeoutSec, $bunkerUserId);
        $data = json_decode($resp['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HeatmapException(
                'Response del Búnker no es JSON válido: ' . json_last_error_msg(),
                $resp['status'], null, null, $resp['body']
            );
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Igual que fetch() pero devuelve el cuerpo CRUDO (string JSON sin parsear).
     * Útil cuando vas a re-emitir la respuesta tal cual a tu propio cliente
     * (proxy transparente sin tocar la estructura).
     *
     * @throws HeatmapException si la response no es 2xx
     */
    public function fetchRaw(string $path, string $method = 'GET', string $body = '', int $timeoutSec = 15, ?int $bunkerUserId = null): string
    {
        return $this->fetchResponse($path, $method, $body, $timeoutSec, $bunkerUserId)['body'];
    }

    /**
     * Versión más completa: devuelve status + headers + body. Lanza excepción
     * solo si hubo error de red o el server rechazó con 4xx/5xx con un body
     * que parece JSON con campo "error". Si quieres procesar 4xx tú mismo
     * sin excepción, usa esta versión y mira $resp['status'].
     *
     * @return array{status:int, headers:array<string,string>, body:string}
     * @throws HeatmapException si error de red o el server devolvió error semántico
     * @throws \RuntimeException si cURL no está disponible
     */
    public function fetchResponse(string $path, string $method = 'GET', string $body = '', int $timeoutSec = 15, ?int $bunkerUserId = null): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('La extensión PHP cURL es requerida para llamadas server-to-server. Instálala (apt install php-curl en Debian/Ubuntu, o pkg-instálala en otro OS) y reinicia PHP.');
        }
        if ($path === '' || strpos($path, self::ALLOWED_PATH_PREFIX) !== 0) {
            throw new \InvalidArgumentException(
                'path debe empezar por "' . self::ALLOWED_PATH_PREFIX . '". Recibido: "' . $path . '".'
            );
        }

        $signed = $this->signRequest($path, $method, $body, $bunkerUserId);
        $url    = $signed->getUrl();

        $ch = curl_init();
        $headers = ['Accept: application/json'];
        if ($body !== '') {
            $headers[] = 'Content-Type: application/json';
        }
        // Importante: NO mandamos Origin. El server lo usa como pista de
        // "modo S2S" (cURL no manda Origin → valida IP no origin).

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,  // incluye headers en el output para parsearlos
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'elbunkerbitcoin/heatmap-client (PHP)',
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw         = curl_exec($ch);
        $errno       = curl_errno($ch);
        $errMsg      = curl_error($ch);
        $status      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new HeatmapException(
                'Error de red llamando al Búnker: ' . $errMsg,
                0, 'network_error', null, ''
            );
        }

        $rawHeaders  = substr((string) $raw, 0, $headerSize);
        $respBody    = substr((string) $raw, $headerSize);
        $headersOut  = self::parseHeaders($rawHeaders);

        if ($status < 200 || $status >= 300) {
            // Intentamos extraer error/detail del body si es JSON
            $errCode = null; $errDetail = null;
            $maybeJson = json_decode($respBody, true);
            if (is_array($maybeJson)) {
                $errCode   = isset($maybeJson['error'])  ? (string) $maybeJson['error']  : null;
                $errDetail = isset($maybeJson['detail']) && is_array($maybeJson['detail']) ? $maybeJson['detail'] : null;
            }
            throw new HeatmapException(
                'Búnker devolvió HTTP ' . $status . ($errCode ? ' — ' . $errCode : ''),
                $status, $errCode, $errDetail, $respBody
            );
        }

        return [
            'status'  => $status,
            'headers' => $headersOut,
            'body'    => $respBody,
        ];
    }

    /**
     * Parser básico de headers HTTP de la respuesta cruda de cURL.
     * Maneja respuestas con múltiples bloques de headers (redirects → 100 Continue → 200).
     * Devolvemos solo el último bloque, que es el de la respuesta final.
     *
     * @return array<string,string>
     */
    private static function parseHeaders(string $raw): array
    {
        $blocks = preg_split("/\r?\n\r?\n/", trim($raw)) ?: [];
        $last   = end($blocks) ?: '';
        $out    = [];
        foreach (preg_split("/\r?\n/", $last) ?: [] as $line) {
            if (strpos($line, ':') === false) continue; // salta status line
            [$k, $v] = explode(':', $line, 2);
            $out[strtolower(trim($k))] = trim($v);
        }
        return $out;
    }

    /** Devuelve la license_key configurada (segura de exponer, es pública). */
    public function getLicenseKey(): string
    {
        return $this->licenseKey;
    }

    /** Devuelve la base URL configurada. */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
