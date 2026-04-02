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
 * Kanboard group and group membership management tool.
 *
 * Covers group CRUD plus member add/remove/list operations.
 * Groups can be assigned to projects via ProjectPermissionTool.
 */
final readonly class GroupTool
{
    private const array ACTIONS = [
        'create', 'get', 'list', 'update', 'remove',
        'add_member', 'remove_member', 'get_members', 'get_user_groups', 'is_member',
        'bulk_add_members',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_group',
            description: 'Manage Kanboard groups and memberships: create, read, update, delete groups; add/remove members, list members, check membership, and bulk member operations.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('group_id', 'Group ID', required: false, integer: true),
                new NumberParameter('user_id', 'User ID (for membership operations)', required: false, integer: true),
                new StringParameter('name', 'Group name', required: false),
                new StringParameter('external_id', 'External ID for LDAP/SSO integration', required: false),
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
            'list' => $this->callApi('getAllGroups'),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'add_member' => $this->addMember($args),
            'remove_member' => $this->removeMember($args),
            'get_members' => $this->getMembers($args),
            'get_user_groups' => $this->getUserGroups($args),
            'is_member' => $this->isMember($args),
            'bulk_add_members' => $this->bulkAddMembers($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function create(array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name is required for create.');
        }

        $params = ['name' => $name];
        $this->addOptionalString($params, $args, 'external_id');

        return $this->callApi('createGroup', $params);
    }

    private function get(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        if ($groupId === null) {
            return ToolResult::error('group_id is required for get.');
        }

        return $this->callApi('getGroup', ['group_id' => $groupId]);
    }

    private function update(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        if ($groupId === null) {
            return ToolResult::error('group_id is required for update.');
        }

        $params = ['group_id' => $groupId];
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'external_id');

        return $this->callApi('updateGroup', $params);
    }

    private function remove(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        if ($groupId === null) {
            return ToolResult::error('group_id is required for remove.');
        }

        return $this->callApi('removeGroup', ['group_id' => $groupId]);
    }

    private function addMember(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($groupId === null || $userId === null) {
            return ToolResult::error('group_id and user_id are required for add_member.');
        }

        return $this->callApi('addGroupMember', [
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);
    }

    private function removeMember(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($groupId === null || $userId === null) {
            return ToolResult::error('group_id and user_id are required for remove_member.');
        }

        return $this->callApi('removeGroupMember', [
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);
    }

    private function getMembers(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        if ($groupId === null) {
            return ToolResult::error('group_id is required for get_members.');
        }

        return $this->callApi('getGroupMembers', ['group_id' => $groupId]);
    }

    private function getUserGroups(array $args): ToolResult
    {
        $userId = $this->requireInt($args, 'user_id');
        if ($userId === null) {
            return ToolResult::error('user_id is required for get_user_groups.');
        }

        return $this->callApi('getMemberGroups', ['user_id' => $userId]);
    }

    private function isMember(array $args): ToolResult
    {
        $groupId = $this->requireInt($args, 'group_id');
        $userId = $this->requireInt($args, 'user_id');

        if ($groupId === null || $userId === null) {
            return ToolResult::error('group_id and user_id are required for is_member.');
        }

        return $this->callApi('isGroupMember', [
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);
    }

    private function bulkAddMembers(array $args): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk_add_members. Each item needs group_id and user_id.');
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || !isset($op['group_id'], $op['user_id'])) {
                return ToolResult::error("Operation {$i}: missing required fields (group_id, user_id).");
            }
            $requests[] = ['method' => 'addGroupMember', 'params' => $op];
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
