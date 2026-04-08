<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitKanboard;

use CarmeloSantana\CoquiToolkitKanboard\Exception\KanboardApiException;
use CarmeloSantana\CoquiToolkitKanboard\Exception\KanboardAuthException;
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
 * Dual-auth mode: an optional admin token (KANBOARD_ADMIN_TOKEN) enables
 * admin-level calls via the application API (username 'jsonrpc') which bypass
 * project-level permission checks. When not set, all calls use primary credentials.
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
        private string $adminToken = '',
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
        $adminToken = getenv('KANBOARD_ADMIN_TOKEN');

        return new self(
            url: $url !== false ? $url : '',
            username: $username !== false ? $username : '',
            token: $token !== false ? $token : '',
            adminToken: $adminToken !== false ? $adminToken : '',
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
        return $this->doCall($method, $params, $this->buildAuthToken());
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
        return $this->doBatch($requests, $this->buildAuthToken());
    }

    /**
     * Get the authenticated user's ID, cached for the lifetime of this client instance.
     *
     * Uses the getMe API call to resolve the current user and caches the result.
     * Returns null if the call fails (e.g. unauthenticated or using application API).
     */
    public function getAuthenticatedUserId(): ?int
    {
        static $cachedId = null;
        static $resolved = false;

        if (!$resolved) {
            $resolved = true;
            try {
                $me = $this->call('getMe');
                if (is_array($me) && isset($me['id'])) {
                    $cachedId = (int) $me['id'];
                }
            } catch (\Throwable) {
                // Application API users don't have getMe — return null
            }
        }

        return $cachedId;
    }

    /**
     * Check if credentials are configured.
     */
    public function isConfigured(): bool
    {
        return $this->resolveUrl() !== '' && $this->resolveUsername() !== '' && $this->resolveToken() !== '';
    }

    /**
     * Check if an admin (application API) token is available.
     */
    public function hasAdminToken(): bool
    {
        return $this->resolveAdminToken() !== '';
    }

    /**
     * Check if primary credentials use the application API (username 'jsonrpc').
     *
     * Application API bypasses all permission checks but cannot access "Me" procedures.
     */
    public function isAppApi(): bool
    {
        return strtolower($this->resolveUsername()) === 'jsonrpc';
    }

    /**
     * Execute a single JSON-RPC 2.0 call using admin (application API) credentials.
     *
     * Uses KANBOARD_ADMIN_TOKEN with username 'jsonrpc' when available.
     * Falls back to primary credentials if no admin token is configured.
     *
     * @param array<string, mixed> $params
     * @throws KanboardApiException On JSON-RPC error or invalid response
     * @throws KanboardAuthException On authentication failure
     */
    public function callAsAdmin(string $method, array $params = []): mixed
    {
        $adminToken = $this->resolveAdminToken();
        if ($adminToken === '') {
            return $this->call($method, $params);
        }

        return $this->doCall($method, $params, base64_encode('jsonrpc:' . $adminToken));
    }

    /**
     * Execute a batch of JSON-RPC 2.0 calls using admin (application API) credentials.
     *
     * @param array<int, array{method: string, params?: array<string, mixed>}> $requests
     * @return array<int, array{success: bool, result?: mixed, error?: string}>
     * @throws KanboardAuthException On authentication failure
     */
    public function batchAsAdmin(array $requests): array
    {
        $adminToken = $this->resolveAdminToken();
        if ($adminToken === '') {
            return $this->batch($requests);
        }

        return $this->doBatch($requests, base64_encode('jsonrpc:' . $adminToken));
    }

    private function buildAuthToken(): string
    {
        return base64_encode($this->resolveUsername() . ':' . $this->resolveToken());
    }

    /**
     * Core JSON-RPC single call transport.
     *
     * @param array<string, mixed> $params
     */
    private function doCall(string $method, array $params, string $authToken): mixed
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
                    'Authorization' => 'Basic ' . $authToken,
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
     * Core JSON-RPC batch call transport.
     *
     * @param array<int, array{method: string, params?: array<string, mixed>}> $requests
     * @return array<int, array{success: bool, result?: mixed, error?: string}>
     */
    private function doBatch(array $requests, string $authToken): array
    {
        if (empty($requests)) {
            return [];
        }

        // Single request — use doCall for simplicity
        if (count($requests) === 1) {
            $req = $requests[0];
            try {
                $result = $this->doCall($req['method'], $req['params'] ?? [], $authToken);
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
                    'Authorization' => 'Basic ' . $authToken,
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

    private function resolveAdminToken(): string
    {
        if ($this->adminToken !== '') {
            return $this->adminToken;
        }

        $env = getenv('KANBOARD_ADMIN_TOKEN');

        return $env !== false ? $env : '';
    }
}
