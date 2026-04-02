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
 * Kanboard project permission management tool.
 *
 * Covers user and group project access, role assignment, and bulk operations.
 * Project roles: project-manager, project-member, project-viewer.
 */
final readonly class ProjectPermissionTool
{
    private const array ACTIONS = [
        'get_users', 'get_assignable_users', 'add_user', 'remove_user',
        'change_user_role', 'get_user_role',
        'add_group', 'remove_group', 'change_group_role',
        'bulk_add_users',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_project_permission',
            description: 'Manage Kanboard project permissions: list/add/remove users and groups, change roles, get assignable users, and bulk user operations. Roles: project-manager, project-member, project-viewer.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new NumberParameter('user_id', 'User ID', required: false, integer: true),
                new NumberParameter('group_id', 'Group ID', required: false, integer: true),
                new StringParameter('role', 'Project role: project-manager, project-member, or project-viewer', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'get_users' => $this->getUsers($args),
            'get_assignable_users' => $this->getAssignableUsers($args),
            'add_user' => $this->addUser($args),
            'remove_user' => $this->removeUser($args),
            'change_user_role' => $this->changeUserRole($args),
            'get_user_role' => $this->getUserRole($args),
            'add_group' => $this->addGroup($args),
            'remove_group' => $this->removeGroup($args),
            'change_group_role' => $this->changeGroupRole($args),
            'bulk_add_users' => $this->bulkAddUsers($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function getUsers(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for get_users.');
        }

        return $this->callApi('getProjectUsers', ['project_id' => $projectId]);
    }

    private function getAssignableUsers(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for get_assignable_users.');
        }

        return $this->callApi('getAssignableUsers', ['project_id' => $projectId]);
    }

    private function addUser(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($projectId === null || $userId === null) {
            return ToolResult::error('project_id and user_id are required for add_user.');
        }

        $params = ['project_id' => $projectId, 'user_id' => $userId];
        $this->addOptionalString($params, $args, 'role');

        return $this->callApi('addProjectUser', $params);
    }

    private function removeUser(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($projectId === null || $userId === null) {
            return ToolResult::error('project_id and user_id are required for remove_user.');
        }

        return $this->callApi('removeProjectUser', [
            'project_id' => $projectId,
            'user_id' => $userId,
        ]);
    }

    private function changeUserRole(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $userId = $this->requireInt($args, 'user_id');
        $role = trim((string) ($args['role'] ?? ''));

        if ($projectId === null || $userId === null || $role === '') {
            return ToolResult::error('project_id, user_id, and role are required for change_user_role.');
        }

        return $this->callApi('changeProjectUserRole', [
            'project_id' => $projectId,
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    private function getUserRole(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($projectId === null || $userId === null) {
            return ToolResult::error('project_id and user_id are required for get_user_role.');
        }

        return $this->callApi('getProjectUserRole', [
            'project_id' => $projectId,
            'user_id' => $userId,
        ]);
    }

    private function addGroup(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $groupId = $this->requireInt($args, 'group_id');

        if ($projectId === null || $groupId === null) {
            return ToolResult::error('project_id and group_id are required for add_group.');
        }

        $params = ['project_id' => $projectId, 'group_id' => $groupId];
        $this->addOptionalString($params, $args, 'role');

        return $this->callApi('addProjectGroup', $params);
    }

    private function removeGroup(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $groupId = $this->requireInt($args, 'group_id');

        if ($projectId === null || $groupId === null) {
            return ToolResult::error('project_id and group_id are required for remove_group.');
        }

        return $this->callApi('removeProjectGroup', [
            'project_id' => $projectId,
            'group_id' => $groupId,
        ]);
    }

    private function changeGroupRole(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $groupId = $this->requireInt($args, 'group_id');
        $role = trim((string) ($args['role'] ?? ''));

        if ($projectId === null || $groupId === null || $role === '') {
            return ToolResult::error('project_id, group_id, and role are required for change_group_role.');
        }

        return $this->callApi('changeProjectGroupRole', [
            'project_id' => $projectId,
            'group_id' => $groupId,
            'role' => $role,
        ]);
    }

    private function bulkAddUsers(array $args): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk_add_users. Each item needs project_id and user_id.');
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || !isset($op['project_id'], $op['user_id'])) {
                return ToolResult::error("Operation {$i}: missing required fields (project_id, user_id).");
            }
            $requests[] = ['method' => 'addProjectUser', 'params' => $op];
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
