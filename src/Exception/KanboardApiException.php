<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Kanboard\Exception;

/**
 * Thrown when a Kanboard JSON-RPC API call returns an error response.
 */
final class KanboardApiException extends \RuntimeException
{
    public static function fromJsonRpc(int $code, string $message, mixed $data = null): self
    {
        $detail = $data !== null ? ' — ' . json_encode($data) : '';

        return new self(
            sprintf('Kanboard API error %d: %s%s', $code, $message, $detail),
            $code,
        );
    }

    public static function invalidResponse(string $reason): self
    {
        return new self('Invalid Kanboard API response: ' . $reason);
    }

    public static function connectionFailed(string $url, string $reason): self
    {
        return new self(sprintf('Failed to connect to Kanboard at %s: %s', $url, $reason));
    }
}
