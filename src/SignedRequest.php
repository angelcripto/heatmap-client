<?php

declare(strict_types=1);

namespace ElBunkerBitcoin\HeatmapClient;

use JsonSerializable;

/**
 * Resultado de Client::signRequest(). Encapsula la URL firmada y su validez.
 *
 * El objeto sabe serializarse a JSON directamente — así puedes hacer
 * `echo $signed->toJson();` desde tu endpoint y la lib JS lo entiende.
 */
final class SignedRequest implements JsonSerializable
{
    /** @var string URL completa con los 4 params de auth en el querystring. */
    private string $url;

    /** @var int Unix timestamp en el que se firmó. */
    private int $signedAt;

    /** @var int Segundos de validez. El server rechaza con 401 timestamp_skew si se excede. */
    private int $ttl;

    public function __construct(string $url, int $signedAt, int $ttl = 60)
    {
        $this->url      = $url;
        $this->signedAt = $signedAt;
        $this->ttl      = $ttl;
    }

    /** URL completa ya firmada, lista para fetch. */
    public function getUrl(): string
    {
        return $this->url;
    }

    /** Unix timestamp en el que se firmó. */
    public function getSignedAt(): int
    {
        return $this->signedAt;
    }

    /** Unix timestamp en el que la firma deja de ser válida. */
    public function getExpiresAt(): int
    {
        return $this->signedAt + $this->ttl;
    }

    /** Segundos restantes hasta que caduque. Negativo si ya expiró. */
    public function getSecondsRemaining(): int
    {
        return $this->getExpiresAt() - time();
    }

    /** True si la firma todavía está dentro de la ventana de validez. */
    public function isValid(): bool
    {
        return $this->getSecondsRemaining() > 0;
    }

    /**
     * Forma esperada por la lib JS (BunkerHeatmap):
     *   { "url": "...", "expires_at": 1234567890 }
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url'        => $this->url,
            'expires_at' => $this->getExpiresAt(),
        ];
    }

    /**
     * Serializa el objeto a JSON listo para devolver desde tu endpoint:
     *
     *     header('Content-Type: application/json');
     *     echo $signedRequest->toJson();
     */
    public function toJson(int $flags = 0): string
    {
        $json = json_encode($this->toArray(), $flags);
        return $json === false ? '{}' : $json;
    }

    /** Soporte para json_encode() directo sobre la instancia. */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Cast a string devuelve la URL firmada. Útil para casos donde solo necesitas la URL. */
    public function __toString(): string
    {
        return $this->url;
    }
}
