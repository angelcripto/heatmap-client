# elbunkerbitcoin/heatmap-client

Cliente PHP oficial para integrar el **heatmap de liquidaciones** de [El Búnker Bitcoin](https://elbunkerbitcoin.com) en tu sitio web o backend. Firma presigned URLs con HMAC-SHA256 para que el browser pueda consumir el CDN sin exponer credenciales, y opcionalmente hace llamadas server-to-server desde tu backend.

## Requisitos

- PHP `^7.4 | ^8.0`
- Extensiones `json`, `openssl`, `curl` (las dos primeras vienen activadas por defecto; `curl` solo es necesaria si vas a usar los métodos `fetch*` server-to-server)

## Instalación

```bash
composer require elbunkerbitcoin/heatmap-client
```

## Configuración

1. Pide a El Búnker tus credenciales (panel admin → Heatmap Licenses). Recibirás:
   - `BUNKER_LICENSE_KEY` — pública, viaja en URLs.
   - `BUNKER_HMAC_SECRET` — privada, **NO sale jamás del backend**.

2. Mete las dos en tu `.env`:
   ```dotenv
   BUNKER_LICENSE_KEY=tu_license_key_aqui
   BUNKER_HMAC_SECRET=tu_hmac_secret_aqui
   ```

---

## Dos modos de integración

| Modo | Usa | Cuándo |
|------|-----|--------|
| **Embed** (browser) | Firma URLs con `signRequest()`, las devuelves al browser, la lib JS las consume | El browser de tu usuario va a cargar el heatmap (lib `<script>` embed). Requiere license con `allowed_origins`. |
| **Server-to-Server (S2S)** | `fetch()` desde tu backend con cURL | Tu backend procesa los datos: cache, agregaciones, reportes, alertas. Requiere license con `allowed_ips` (la IP de tu server). |

El server detecta el modo automáticamente por la presencia del header `Origin` (browser sí, cURL no). Una misma license puede tener ambos campos configurados para servir a los dos modos.

---

## Modo Embed (browser)

### 1. Endpoint en tu backend (`/heatmap-sign`)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use ElBunkerBitcoin\HeatmapClient\Client;

$client = new Client(
    getenv('BUNKER_LICENSE_KEY'),
    getenv('BUNKER_HMAC_SECRET')
);

$path = $_GET['path'] ?? '';
header('Content-Type: application/json');
echo $client->signRequest($path)->toJson();
```

### 2. HTML

```html
<div id="mi-heatmap" style="height:700px"></div>
<script src="https://elbunkerbitcoin.com/cdn/bunker-heatmap.js"></script>
<script>
  new BunkerHeatmap({
    mount: '#mi-heatmap',
    signEndpoint: '/heatmap-sign',
    symbol: 'BTCUSDT',
    interval: '5m'
  });
</script>
```

La lib pide a `/heatmap-sign?path=...` cada URL del heatmap, recibe la URL ya firmada, y la fetchea directamente desde el browser. **No tienes que escribir más PHP**.

---

## Modo Server-to-Server (S2S)

Para cuando quieres procesar los datos en tu backend sin browser de por medio.

### Ejemplo: cachear OI history en tu server

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use ElBunkerBitcoin\HeatmapClient\Client;
use ElBunkerBitcoin\HeatmapClient\HeatmapException;

$client = new Client(
    getenv('BUNKER_LICENSE_KEY'),
    getenv('BUNKER_HMAC_SECRET')
);

try {
    $now    = time();
    $from   = $now - 86400 * 7; // últimos 7 días
    $oi     = $client->fetch("/api/heatmap/oi-history?symbol=BTCUSDT&interval=5m&from={$from}&to={$now}");

    // $oi es un array PHP ya decodificado del JSON.
    // Cachéalo, agrégalo, expórtalo, lo que necesites:
    file_put_contents('/tmp/oi-cache.json', json_encode($oi));

} catch (HeatmapException $e) {
    error_log("Búnker error: HTTP {$e->getHttpStatus()} — {$e->getErrorCode()}");
    error_log("Detail: " . json_encode($e->getDetail()));
    // Decide si fallback a cache vieja, retry, alerta, etc.
}
```

### Variantes

```php
// Si quieres re-emitir la respuesta cruda a tu propio cliente (proxy):
header('Content-Type: application/json');
echo $client->fetchRaw('/api/heatmap/liquidation-map?symbol=BTCUSDT&interval=5m');

// Si necesitas status + headers además del body:
$resp = $client->fetchResponse('/api/heatmap/oi-history?symbol=BTCUSDT&interval=5m');
// $resp = ['status' => 200, 'headers' => [...], 'body' => '...']
```

### Configurar la license para S2S

En el panel admin del Búnker, tu license tiene que tener tu IP de server en `allowed_ips`:
- Si la conoces, métela directamente (IPv4, IPv6 o CIDR).
- Si no, una primera llamada va a fallar con `ip_not_allowed` y el `detail` te dirá `ip_seen` (la IP que vio el server). Añádela y reintenta.

---

## Seguridad

- El `hmac_secret` **nunca** llega al browser ni se comparte públicamente.
- Cada URL firmada caduca en **60 segundos**.
- Cada URL lleva un **nonce único** (1 uso). Replays = rechazados con `nonce_replay`.
- El Búnker valida también:
  - **Modo embed**: `Origin` del browser contra `allowed_origins`.
  - **Modo S2S**: IP del cliente contra `allowed_ips`.
  - **Rate limit** por license (configurable).

Si una license se filtra, rótala desde el panel admin y todas las URLs firmadas con la versión vieja dejan de funcionar.

---

## API

### `Client::__construct(string $licenseKey, string $hmacSecret, string $baseUrl = 'https://elbunkerbitcoin.com')`

Lanza `InvalidArgumentException` si las credenciales están vacías o tienen formato sospechoso (no hex, demasiado cortas).

### Firma (modo embed)

| Método | Devuelve | Uso |
|--------|----------|-----|
| `signRequest(string $path, string $method = 'GET', string $body = ''): SignedRequest` | objeto con la URL firmada y metadata | endpoint que devuelve JSON al browser |
| `getSignedUrl(string $path, ...): string` | solo la URL firmada como string | atajo |
| `Client::quickSign(string $key, string $secret, string $path): string` | URL firmada en 1 línea | one-shots |

### Llamadas server-to-server

| Método | Devuelve | Uso |
|--------|----------|-----|
| `fetch(string $path, string $method = 'GET', string $body = '', int $timeoutSec = 15): array` | respuesta JSON decodificada | caso típico: procesar datos |
| `fetchRaw(...): string` | cuerpo crudo (string JSON) | proxy transparente |
| `fetchResponse(...): array` | `['status', 'headers', 'body']` | control completo |

Lanzan `HeatmapException` si la response no es 2xx, con `getHttpStatus()`, `getErrorCode()`, `getDetail()`, `getRawBody()`.

### `SignedRequest`

- `->getUrl(): string`
- `->getExpiresAt(): int` (Unix timestamp)
- `->getSecondsRemaining(): int`
- `->isValid(): bool`
- `->toArray(): array` → `['url' => ..., 'expires_at' => ...]`
- `->toJson(): string`
- Implementa `JsonSerializable` y `__toString()`

---

## Soporte

Email: soporte@elbunkerbitcoin.com
