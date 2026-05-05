<?php

declare(strict_types=1);

/**
 * Ejemplo mínimo del endpoint /heatmap-sign que monta el cliente en su backend.
 *
 * Adáptalo a tu framework (Slim/Laravel/Symfony/lo que sea) — la lógica core
 * son las 3 líneas de Client::__construct + signRequest + toJson.
 *
 * Requisitos previos:
 *   - composer require elbunkerbitcoin/heatmap-client
 *   - .env con BUNKER_LICENSE_KEY y BUNKER_HMAC_SECRET
 *   - Cargar el .env (con vlucas/phpdotenv, Symfony Dotenv, o getenv() puro)
 */

require __DIR__ . '/../vendor/autoload.php';

use ElBunkerBitcoin\HeatmapClient\Client;

// --- Cargar credenciales ---
// Si usas vlucas/phpdotenv:
//   Dotenv\Dotenv::createImmutable(__DIR__)->load();
// Si las metiste como env vars del sistema, getenv() las lee directamente.
$licenseKey = getenv('BUNKER_LICENSE_KEY') ?: '';
$hmacSecret = getenv('BUNKER_HMAC_SECRET') ?: '';

if ($licenseKey === '' || $hmacSecret === '') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Credenciales no configuradas en el .env (BUNKER_LICENSE_KEY y BUNKER_HMAC_SECRET)',
    ]);
    exit;
}

// --- Validar input ---
$path = $_GET['path'] ?? '';
if ($path === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Falta el query param "path"']);
    exit;
}

// --- Firmar y devolver ---
try {
    $client = new Client($licenseKey, $hmacSecret);
    $signed = $client->signRequest($path);
    header('Content-Type: application/json');
    echo $signed->toJson();
} catch (InvalidArgumentException $e) {
    // Path no autorizado, credencial mal formada, etc.
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
