<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Kanboard\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Kanboard\KanboardClient;

/**
 * Kanboard user management tool.
 *
 * Covers user CRUD, enable/disable, and bulk operations.
 * Roles: app-admin, app-manager, app-user.
 */
final readonly class UserTool
{
    private const array ACTIONS = [
        'create', 'get', 'get_by_name', 'list', 'update', 'remove',
        'enable', 'disable', 'is_active', 'get_me',
        'bulk_create', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_user',
            description: 'Manage Kanboard users: create, read, update, delete, enable/disable, check active status, get authenticated user info, and bulk operations. Roles: app-admin, app-manager, app-user.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('user_id', 'User ID', required: false, integer: true),
                new StringParameter('username', 'Username (login name)', required: false),
                new StringParameter('password', 'Password for create/update', required: false),
                new StringParameter('name', 'Full display name', required: false),
                new StringParameter('email', 'Email address', required: false),
                new StringParameter('role', 'Application role: app-admin, app-manager, or app-user', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'create' => $this->create($args),
            'get' => $this->get($args),
            'get_by_name' => $this->getByName($args),
            'list' => $this->callApi('getAllUsers'),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'enable' => $this->toggle($args, 'enableUser'),
            'disable' => $this->toggle($args, 'disableUser'),
            'is_active' => $this->isActive($args),
            'get_me' => $this->callApi('getMe'),
            'bulk_create' => $this->executeBulk($args, 'createUser', 'username'),
            'bulk_remove' => $this->executeBulk($args, 'removeUser', 'user_id'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function create(array $args): ToolResult
    {
        $username = trim((string) ($args['username'] ?? ''));
        $password = trim((string) ($args['password'] ?? ''));

        if ($username === '' || $password === '') {
            return ToolResult::error('username and password are required for create.');
        }

        $params = ['username' => $username, 'password' => $password];
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'email');
        $this->addOptionalString($params, $args, 'role');

        return $this->callApi('createUser', $params);
    }

    private function get(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for get.');
        }

        return $this->callApi('getUser', ['user_id' => $userId]);
    }

    private function getByName(array $args): ToolResult
    {
        $username = trim((string) ($args['username'] ?? ''));
        if ($username === '') {
            return ToolResult::error('username is required for get_by_name.');
        }

        return $this->callApi('getUserByName', ['username' => $username]);
    }

    private function update(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for update.');
        }

        $params = ['id' => $userId];
        $this->addOptionalString($params, $args, 'username');
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'email');
        $this->addOptionalString($params, $args, 'role');
        $this->addOptionalString($params, $args, 'password');

        return $this->callApi('updateUser', $params);
    }

    private function remove(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for remove.');
        }

        return $this->callApi('removeUser', ['user_id' => $userId]);
    }

    private function toggle(array $args, string $method): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required.');
        }

        return $this->callApi($method, ['user_id' => $userId]);
    }

    private function isActive(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for is_active.');
        }

        return $this->callApi('isActiveUser', ['user_id' => $userId]);
    }

    private function executeBulk(array $args, string $method, string $requiredField): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk actions.');
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || !isset($op[$requiredField])) {
                return ToolResult::error("Operation {$i}: missing required field '{$requiredField}'.");
            }
            $requests[] = ['method' => $method, 'params' => $op];
        }

        return $this->executeBatch($requests);
    }

    private function callApi(string $method, array $params = []): ToolResult
    {
        try {
            $result = $this->client->call($method, $params);

            return ToolResult::success(
                json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null',
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function executeBatch(array $requests): ToolResult
    {
        try {
            $results = $this->client->batch($requests);

            return ToolResult::success(
                json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function parseOperations(array $args): ?array
    {
        $raw = trim((string) ($args['operations'] ?? ''));
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function requireInt(array $args, string $key): ?int
    {
        if (!isset($args[$key]) || $args[$key] === '') {
            return null;
        }

        return (int) $args[$key];
    }

    private function addOptionalString(array &$params, array $args, string $key): void
    {
        $value = trim((string) ($args[$key] ?? ''));
        if ($value !== '') {
            $params[$key] = $value;
        }
    }
}
