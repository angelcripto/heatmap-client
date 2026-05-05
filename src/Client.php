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
     *
     * @throws InvalidArgumentException si el path no empieza por /api/heatmap/.
     */
    public function signRequest(string $path, string $method = 'GET', string $body = ''): SignedRequest
    {
        if ($path === '' || strpos($path, self::ALLOWED_PATH_PREFIX) !== 0) {
            throw new InvalidArgumentException(
                'path debe empezar por "' . self::ALLOWED_PATH_PREFIX . '". Recibido: "' . $path . '". '
                . 'Esto previene que un cliente malicioso use tu firmador como "oracle" para firmar URLs arbitrarias del Búnker.'
            );
        }

        $method = strtoupper($method);
        $ts     = (string) time();
        $nonce  = bin2hex(random_bytes(self::NONCE_BYTES));

        // Payload firmado: ts.nonce.METHOD.path.body
        // El "path" aquí es el path original SIN los 4 query params de auth
        // (los añadimos después). Si los incluyéramos en el payload, sería
        // circular: la firma dependería de sí misma.
        $payload = $ts . '.' . $nonce . '.' . $method . '.' . $path . '.' . $body;
        $sig     = hash_hmac('sha256', $payload, $this->hmacSecret);

        // Construimos la URL final añadiendo los 4 params de auth como
        // querystring extra. Soportamos paths que ya tengan ? o que no.
        $sep = (strpos($path, '?') === false) ? '?' : '&';
        $authQs = $sep
                . '_bk_lic='   . rawurlencode($this->licenseKey)
                . '&_bk_ts='   . rawurlencode($ts)
                . '&_bk_nonce='. rawurlencode($nonce)
                . '&_bk_sig='  . rawurlencode($sig);

        $signedUrl = $this->baseUrl . $path . $authQs;

        return new SignedRequest($signedUrl, (int) $ts, 60);
    }

    /**
     * Firma una URL en una sola línea. Útil para casos rápidos donde no
     * necesitas el objeto SignedRequest completo.
     *
     *     $url = (new Client($key, $secret))->getSignedUrl('/api/heatmap/...');
     */
    public function getSignedUrl(string $path, string $method = 'GET', string $body = ''): string
    {
        return $this->signRequest($path, $method, $body)->getUrl();
    }

    /**
     * Helper estático para flujos one-shot. NO usarlo si vas a firmar muchas
     * requests — instancia el Client una vez y reusa.
     */
    public static function quickSign(string $licenseKey, string $hmacSecret, string $path): string
    {
        return (new self($licenseKey, $hmacSecret))->getSignedUrl($path);
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
