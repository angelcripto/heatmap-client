<?php

declare(strict_types=1);

namespace ElBunkerBitcoin\HeatmapClient;

use RuntimeException;

/**
 * Excepción lanzada cuando una llamada server-to-server al heatmap CDN falla.
 * Lleva el código HTTP, el cuerpo crudo (si lo hay) y el campo `error` del
 * server (si la respuesta era JSON con esa estructura).
 */
class HeatmapException extends RuntimeException
{
    private int $httpStatus;
    private ?string $errorCode;
    private ?array $detail;
    private string $rawBody;

    public function __construct(
        string $message,
        int $httpStatus = 0,
        ?string $errorCode = null,
        ?array $detail = null,
        string $rawBody = ''
    ) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode  = $errorCode;
        $this->detail     = $detail;
        $this->rawBody    = $rawBody;
    }

    /** HTTP status del response del Búnker (401, 403, 429, 500, 0 si fue error de red). */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Código de error semántico devuelto por el Búnker (ej: 'origin_not_allowed', 'rate_limited'). */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** Detalle estructurado del error (si el server lo devolvió). */
    public function getDetail(): ?array
    {
        return $this->detail;
    }

    /** Cuerpo crudo de la response, por si necesitas inspeccionarlo. */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }
}
