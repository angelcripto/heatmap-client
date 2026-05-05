# elbunkerbitcoin/heatmap-client

Cliente PHP oficial para integrar el **heatmap de liquidaciones** de [El Búnker Bitcoin](https://elbunkerbitcoin.com) en tu sitio web. Firma presigned URLs con HMAC-SHA256 para que el browser pueda consumir el CDN sin exponer credenciales.

## Requisitos

- PHP `^7.4 | ^8.0`
- Extensiones `json` y `openssl` (vienen activadas por defecto)

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
   BUNKER_LICENSE_KEY=647d4ae9dc41e01aef34f366a4130c9322fba01cf5cc58ca
   BUNKER_HMAC_SECRET=c313b44a26fee86eaa4323ab37f8498b9e10bad429d1c7925fcf13157159fca9a25e26dc9ff45bc5ebdb9ca148e3dece
   ```

## Uso

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

Eso es todo. La lib pide a `/heatmap-sign?path=...` cada URL del heatmap, recibe la URL ya firmada, y la fetchea directamente desde el browser.

## Seguridad

- El `hmac_secret` **nunca** llega al browser.
- Cada URL firmada caduca en **60 segundos**.
- Cada URL lleva un **nonce único** (1 uso). Replays = rechazados.
- El Búnker valida también el `Origin` del browser contra `allowed_origins` configurados en tu license. Si alguien copia el secret y lo usa desde otro dominio, falla.

## API

### `Client::__construct(string $licenseKey, string $hmacSecret, string $baseUrl = self::DEFAULT_BASE_URL)`

Lanza `InvalidArgumentException` si las credenciales están vacías o tienen formato inesperado.

### `Client::signRequest(string $path, string $method = 'GET', string $body = ''): SignedRequest`

Firma una request. El `path` debe empezar por `/api/heatmap/`. Si pasas un path arbitrario, lanza `InvalidArgumentException` (defensa contra usar este firmador como "oracle" para URLs no autorizadas).

### `Client::getSignedUrl(string $path, string $method = 'GET', string $body = ''): string`

Atajo: devuelve solo la URL firmada como string.

### `Client::quickSign(string $licenseKey, string $hmacSecret, string $path): string`

Helper estático para casos one-shot. Si vas a firmar muchas, instancia `Client` y reusa.

### `SignedRequest`

- `->getUrl(): string` — URL completa firmada
- `->getExpiresAt(): int` — Unix timestamp en el que caduca
- `->getSecondsRemaining(): int`
- `->isValid(): bool`
- `->toArray(): array` — `['url' => ..., 'expires_at' => ...]`
- `->toJson(): string` — JSON listo para devolver
- Implementa `JsonSerializable` y `__toString()`

## Soporte

Email: soporte@elbunkerbitcoin.com
