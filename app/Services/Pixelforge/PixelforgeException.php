<?php

namespace App\Services\Pixelforge;

use App\Enums\PixelforgeErrorCode;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A non-2xx response from Pixelforge.
 */
class PixelforgeException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?PixelforgeErrorCode $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        return new self(
            status: $response->status(),
            errorCode: PixelforgeErrorCode::tryFrom((string) $response->json('error.code')),
            message: (string) ($response->json('error.message') ?? "Pixelforge responded with HTTP {$response->status()}."),
            retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * Whether retrying the same request later may succeed.
     */
    public function isRetryable(): bool
    {
        return $this->errorCode?->isTransient()
            || $this->status === SymfonyResponse::HTTP_REQUEST_TIMEOUT
            || $this->status >= SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR;
    }

    public function userMessage(): string
    {
        return $this->errorCode?->userMessage() ?? $this->getMessage();
    }
}
