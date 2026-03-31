<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Kanboard;

use CoquiBot\Toolkits\Kanboard\Exception\KanboardApiException;
use CoquiBot\Toolkits\Kanboard\Exception\KanboardAuthException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * JSON-RPC 2.0 client for the Kanboard API.
 *
 * Supports single calls, batch requests, and all three Kanboard auth methods
 * (application API, user API with password, user API with personal token)
 * through HTTP Basic Authentication.
 *
 * Uses lazy credential resolution for hot-reload support — after the LLM sets
 * credentials via the credentials tool, the next call picks them up immediately.
 */
final class KanboardClient
{
    private int $requestId = 1;
    private HttpClientInterface $httpClient;

    public function __construct(
        private string $url = '',
        private string $username = '',
        private string $token = '',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 30]);
    }

    /**
     * Factory method for ToolkitDiscovery — reads credentials from environment.
     */
    public static function fromEnv(): self
    {
        $url = getenv('KANBOARD_URL');
        $username = getenv('KANBOARD_USERNAME');
        $token = getenv('KANBOARD_API_TOKEN');

        return new self(
            url: $url !== false ? $url : '',
            username: $username !== false ? $username : '',
            token: $token !== false ? $token : '',
        );
    }

    /**
     * Execute a single JSON-RPC 2.0 call.
     *
     * @param array<string, mixed> $params
     * @throws KanboardApiException On JSON-RPC error or invalid response
     * @throws KanboardAuthException On authentication failure
     */
    public function call(string $method, array $params = []): mixed
    {
        $url = $this->resolveUrl();
        $requestId = $this->requestId++;

        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'id' => $requestId,
            'params' => empty($params) ? (object) [] : $params,
        ];

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . $this->buildAuthToken(),
                ],
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 401) {
                throw KanboardAuthException::unauthorized();
            }

            if ($statusCode === 403) {
                throw KanboardAuthException::forbidden();
            }

            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401) {
                throw KanboardAuthException::unauthorized();
            }

            if ($statusCode === 403) {
                throw KanboardAuthException::forbidden();
            }

            throw KanboardApiException::connectionFailed($url, $e->getMessage());
        } catch (KanboardAuthException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw KanboardApiException::connectionFailed($url, $e->getMessage());
        }

        if (!is_array($data)) {
            throw KanboardApiException::invalidResponse('Expected JSON object');
        }

        if (isset($data['error'])) {
            $error = $data['error'];
            throw KanboardApiException::fromJsonRpc(
                code: (int) ($error['code'] ?? -1),
                message: (string) ($error['message'] ?? 'Unknown error'),
                data: $error['data'] ?? null,
            );
        }

        return $data['result'] ?? null;
    }

    /**
     * Execute a batch of JSON-RPC 2.0 calls in a single HTTP request.
     *
     * Kanboard supports batch requests per the JSON-RPC 2.0 spec.
     * Each request is an array with 'method' and optional 'params' keys.
     *
     * @param array<int, array{method: string, params?: array<string, mixed>}> $requests
     * @return array<int, array{success: bool, result?: mixed, error?: string}> Results in corresponding order
     * @throws KanboardAuthException On authentication failure
     */
    public function batch(array $requests): array
    {
        if (empty($requests)) {
            return [];
        }

        // Single request — use normal call for simplicity
        if (count($requests) === 1) {
            $req = $requests[0];
            try {
                $result = $this->call($req['method'], $req['params'] ?? []);
                return [['success' => true, 'result' => $result]];
            } catch (KanboardApiException $e) {
                return [['success' => false, 'error' => $e->getMessage()]];
            }
        }

        $url = $this->resolveUrl();
        $payload = [];
        $idMap = [];

        foreach ($requests as $index => $req) {
            $requestId = $this->requestId++;
            $idMap[$requestId] = $index;

            $payload[] = [
                'jsonrpc' => '2.0',
                'method' => $req['method'],
                'id' => $requestId,
                'params' => empty($req['params']) ? (object) [] : $req['params'],
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . $this->buildAuthToken(),
                ],
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 401) {
                throw KanboardAuthException::unauthorized();
            }

            if ($statusCode === 403) {
                throw KanboardAuthException::forbidden();
            }

            $data = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        } catch (KanboardAuthException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw KanboardApiException::connectionFailed($url, $e->getMessage());
        }

        if (!is_array($data)) {
            throw KanboardApiException::invalidResponse('Expected JSON array for batch response');
        }

        // Map responses back to request order
        $results = array_fill(0, count($requests), ['success' => false, 'error' => 'No response received']);

        foreach ($data as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }

            $id = (int) $item['id'];
            if (!isset($idMap[$id])) {
                continue;
            }

            $index = $idMap[$id];

            if (isset($item['error'])) {
                $error = $item['error'];
                $results[$index] = [
                    'success' => false,
                    'error' => sprintf(
                        'Error %d: %s',
                        (int) ($error['code'] ?? -1),
                        (string) ($error['message'] ?? 'Unknown error'),
                    ),
                ];
            } else {
                $results[$index] = [
                    'success' => true,
                    'result' => $item['result'] ?? null,
                ];
            }
        }

        return $results;
    }

    /**
     * Check if credentials are configured.
     */
    public function isConfigured(): bool
    {
        return $this->resolveUrl() !== '' && $this->resolveUsername() !== '' && $this->resolveToken() !== '';
    }

    private function buildAuthToken(): string
    {
        return base64_encode($this->resolveUsername() . ':' . $this->resolveToken());
    }

    private function resolveUrl(): string
    {
        if ($this->url !== '') {
            return $this->url;
        }

        $env = getenv('KANBOARD_URL');

        return $env !== false ? $env : '';
    }

    private function resolveUsername(): string
    {
        if ($this->username !== '') {
            return $this->username;
        }

        $env = getenv('KANBOARD_USERNAME');

        return $env !== false ? $env : '';
    }

    private function resolveToken(): string
    {
        if ($this->token !== '') {
            return $this->token;
        }

        $env = getenv('KANBOARD_API_TOKEN');

        return $env !== false ? $env : '';
    }
}
