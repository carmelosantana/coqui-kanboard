<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitKanboard\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\CoquiToolkitKanboard\KanboardClient;

/**
 * Kanboard administration tool.
 *
 * Consolidates user management, group management, and project permissions
 * into a single admin-oriented tool. Admin-sensitive API calls route through
 * callAsAdmin() which uses the application API token when available.
 *
 * Roles: app-admin, app-manager, app-user (application-level);
 * project-manager, project-member, project-viewer (project-level).
 */
final readonly class AdminTool
{
    private const int MAX_BULK_SIZE = 50;

    private const array ACTIONS = [
        // User management
        'user_create', 'user_get', 'user_get_by_name', 'user_list',
        'user_update', 'user_remove', 'user_enable', 'user_disable', 'user_is_active',
        // Group management
        'group_create', 'group_get', 'group_list', 'group_update', 'group_remove',
        'group_add_member', 'group_remove_member', 'group_get_members',
        'group_get_user_groups', 'group_is_member',
        // Project permissions
        'perm_get_users', 'perm_get_assignable', 'perm_add_user', 'perm_remove_user',
        'perm_change_user_role', 'perm_get_user_role',
        'perm_add_group', 'perm_remove_group', 'perm_change_group_role',
        // Bulk operations
        'bulk_user_create', 'bulk_user_remove',
        'bulk_group_add_members', 'bulk_group_remove_members',
        'bulk_perm_add_users', 'bulk_perm_change_roles',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_admin',
            description: 'Kanboard administration: manage users (CRUD, enable/disable, roles), groups (CRUD, membership), and project permissions (user/group access, role assignment). Uses admin API token when available for elevated permissions. Bulk operations supported.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('user_id', 'User ID', required: false, integer: true),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new NumberParameter('group_id', 'Group ID', required: false, integer: true),
                new StringParameter('username', 'Username (login name)', required: false),
                new StringParameter('password', 'Password for user create/update', required: false),
                new StringParameter('name', 'Full display name or group name', required: false),
                new StringParameter('email', 'Email address', required: false),
                new StringParameter('role', 'Role: app-admin/app-manager/app-user or project-manager/project-member/project-viewer', required: false),
                new StringParameter('external_id', 'External ID for LDAP/SSO group integration', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions (max 50)', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            // User management
            'user_create' => $this->userCreate($args),
            'user_get' => $this->userGet($args),
            'user_get_by_name' => $this->userGetByName($args),
            'user_list' => $this->callAdmin('getAllUsers'),
            'user_update' => $this->userUpdate($args),
            'user_remove' => $this->userRemove($args),
            'user_enable' => $this->userToggle($args, 'enableUser'),
            'user_disable' => $this->userToggle($args, 'disableUser'),
            'user_is_active' => $this->userIsActive($args),
            // Group management
            'group_create' => $this->groupCreate($args),
            'group_get' => $this->groupGet($args),
            'group_list' => $this->callAdmin('getAllGroups'),
            'group_update' => $this->groupUpdate($args),
            'group_remove' => $this->groupRemove($args),
            'group_add_member' => $this->groupAddMember($args),
            'group_remove_member' => $this->groupRemoveMember($args),
            'group_get_members' => $this->groupGetMembers($args),
            'group_get_user_groups' => $this->groupGetUserGroups($args),
            'group_is_member' => $this->groupIsMember($args),
            // Project permissions
            'perm_get_users' => $this->permGetUsers($args),
            'perm_get_assignable' => $this->permGetAssignable($args),
            'perm_add_user' => $this->permAddUser($args),
            'perm_remove_user' => $this->permRemoveUser($args),
            'perm_change_user_role' => $this->permChangeUserRole($args),
            'perm_get_user_role' => $this->permGetUserRole($args),
            'perm_add_group' => $this->permAddGroup($args),
            'perm_remove_group' => $this->permRemoveGroup($args),
            'perm_change_group_role' => $this->permChangeGroupRole($args),
            // Bulk operations
            'bulk_user_create' => $this->bulkAdmin($args, 'createUser', 'username'),
            'bulk_user_remove' => $this->bulkAdmin($args, 'removeUser', 'user_id'),
            'bulk_group_add_members' => $this->bulkAdmin($args, 'addGroupMember', ['group_id', 'user_id']),
            'bulk_group_remove_members' => $this->bulkAdmin($args, 'removeGroupMember', ['group_id', 'user_id']),
            'bulk_perm_add_users' => $this->bulkAdmin($args, 'addProjectUser', ['project_id', 'user_id']),
            'bulk_perm_change_roles' => $this->bulkAdmin($args, 'changeProjectUserRole', ['project_id', 'user_id', 'role']),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    // --- User management ---

    private function userCreate(array $args): ToolResult
    {
        $username = trim((string) ($args['username'] ?? ''));
        $password = trim((string) ($args['password'] ?? ''));

        if ($username === '' || $password === '') {
            return ToolResult::error('username and password are required for user_create.');
        }

        $params = ['username' => $username, 'password' => $password];
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'email');
        $this->addOptionalString($params, $args, 'role');

        return $this->callAdmin('createUser', $params);
    }

    private function userGet(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for user_get.');
        }

        return $this->callAdmin('getUser', ['user_id' => $userId]);
    }

    private function userGetByName(array $args): ToolResult
    {
        $username = trim((string) ($args['username'] ?? ''));
        if ($username === '') {
            return ToolResult::error('username is required for user_get_by_name.');
        }

        return $this->callAdmin('getUserByName', ['username' => $username]);
    }

    private function userUpdate(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for user_update.');
        }

        $params = ['id' => $userId];
        $this->addOptionalString($params, $args, 'username');
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'email');
        $this->addOptionalString($params, $args, 'role');
        $this->addOptionalString($params, $args, 'password');

        return $this->callAdmin('updateUser', $params);
    }

    private function userRemove(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for user_remove.');
        }

        return $this->callAdmin('removeUser', ['user_id' => $userId]);
    }

    private function userToggle(array $args, string $method): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required.');
        }

        return $this->callAdmin($method, ['user_id' => $userId]);
    }

    private function userIsActive(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for user_is_active.');
        }

        return $this->callAdmin('isActiveUser', ['user_id' => $userId]);
    }

    // --- Group management ---

    private function groupCreate(array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name is required for group_create.');
        }

        $params = ['name' => $name];
        $this->addOptionalString($params, $args, 'external_id');

        return $this->callAdmin('createGroup', $params);
    }

    private function groupGet(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        if ($groupId === null) {
            return ToolResult::error('group_id is required for group_get.');
        }

        return $this->callAdmin('getGroup', ['group_id' => $groupId]);
    }

    private function groupUpdate(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        if ($groupId === null) {
            return ToolResult::error('group_id is required for group_update.');
        }

        $params = ['group_id' => $groupId];
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'external_id');

        return $this->callAdmin('updateGroup', $params);
    }

    private function groupRemove(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        if ($groupId === null) {
            return ToolResult::error('group_id is required for group_remove.');
        }

        return $this->callAdmin('removeGroup', ['group_id' => $groupId]);
    }

    private function groupAddMember(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($groupId === null || $userId === null) {
            return ToolResult::error('group_id and user_id are required for group_add_member.');
        }

        return $this->callAdmin('addGroupMember', [
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);
    }

    private function groupRemoveMember(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($groupId === null || $userId === null) {
            return ToolResult::error('group_id and user_id are required for group_remove_member.');
        }

        return $this->callAdmin('removeGroupMember', [
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);
    }

    private function groupGetMembers(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        if ($groupId === null) {
            return ToolResult::error('group_id is required for group_get_members.');
        }

        return $this->callAdmin('getGroupMembers', ['group_id' => $groupId]);
    }

    private function groupGetUserGroups(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for group_get_user_groups.');
        }

        return $this->callAdmin('getMemberGroups', ['user_id' => $userId]);
    }

    private function groupIsMember(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($groupId === null || $userId === null) {
            return ToolResult::error('group_id and user_id are required for group_is_member.');
        }

        return $this->callAdmin('isGroupMember', [
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);
    }

    // --- Project permissions ---

    private function permGetUsers(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for perm_get_users.');
        }

        return $this->callAdmin('getProjectUsers', ['project_id' => $projectId]);
    }

    private function permGetAssignable(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for perm_get_assignable.');
        }

        return $this->callAdmin('getAssignableUsers', ['project_id' => $projectId]);
    }

    private function permAddUser(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($projectId === null || $userId === null) {
            return ToolResult::error('project_id and user_id are required for perm_add_user.');
        }

        $params = ['project_id' => $projectId, 'user_id' => $userId];
        $this->addOptionalString($params, $args, 'role');

        return $this->callAdmin('addProjectUser', $params);
    }

    private function permRemoveUser(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($projectId === null || $userId === null) {
            return ToolResult::error('project_id and user_id are required for perm_remove_user.');
        }

        return $this->callAdmin('removeProjectUser', [
            'project_id' => $projectId,
            'user_id' => $userId,
        ]);
    }

    private function permChangeUserRole(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $userId = $this->requireInt($args, 'user_id');
        $role = trim((string) ($args['role'] ?? ''));

        if ($projectId === null || $userId === null || $role === '') {
            return ToolResult::error('project_id, user_id, and role are required for perm_change_user_role.');
        }

        return $this->callAdmin('changeProjectUserRole', [
            'project_id' => $projectId,
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    private function permGetUserRole(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($projectId === null || $userId === null) {
            return ToolResult::error('project_id and user_id are required for perm_get_user_role.');
        }

        return $this->callAdmin('getProjectUserRole', [
            'project_id' => $projectId,
            'user_id' => $userId,
        ]);
    }

    private function permAddGroup(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $groupId = $this->requireInt($args, 'group_id');

        if ($projectId === null || $groupId === null) {
            return ToolResult::error('project_id and group_id are required for perm_add_group.');
        }

        $params = ['project_id' => $projectId, 'group_id' => $groupId];
        $this->addOptionalString($params, $args, 'role');

        return $this->callAdmin('addProjectGroup', $params);
    }

    private function permRemoveGroup(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $groupId = $this->requireInt($args, 'group_id');

        if ($projectId === null || $groupId === null) {
            return ToolResult::error('project_id and group_id are required for perm_remove_group.');
        }

        return $this->callAdmin('removeProjectGroup', [
            'project_id' => $projectId,
            'group_id' => $groupId,
        ]);
    }

    private function permChangeGroupRole(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $groupId = $this->requireInt($args, 'group_id');
        $role = trim((string) ($args['role'] ?? ''));

        if ($projectId === null || $groupId === null || $role === '') {
            return ToolResult::error('project_id, group_id, and role are required for perm_change_group_role.');
        }

        return $this->callAdmin('changeProjectGroupRole', [
            'project_id' => $projectId,
            'group_id' => $groupId,
            'role' => $role,
        ]);
    }

    // --- Bulk operations ---

    /**
     * @param string|string[] $requiredFields
     */
    private function bulkAdmin(array $args, string $method, string|array $requiredFields): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk actions.');
        }

        if (count($operations) > self::MAX_BULK_SIZE) {
            return ToolResult::error(sprintf('Bulk operations capped at %d items. Got %d.', self::MAX_BULK_SIZE, count($operations)));
        }

        $fields = is_string($requiredFields) ? [$requiredFields] : $requiredFields;

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op)) {
                return ToolResult::error("Operation {$i}: must be an object.");
            }
            foreach ($fields as $field) {
                if (!isset($op[$field])) {
                    return ToolResult::error("Operation {$i}: missing required field '{$field}'.");
                }
            }
            $requests[] = ['method' => $method, 'params' => $op];
        }

        return $this->executeBatchAdmin($requests);
    }

    // --- Helpers ---

    private function callAdmin(string $method, array $params = []): ToolResult
    {
        try {
            $result = $this->client->callAsAdmin($method, $params);

            return ToolResult::success(
                json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null',
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function executeBatchAdmin(array $requests): ToolResult
    {
        try {
            $results = $this->client->batchAsAdmin($requests);

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
