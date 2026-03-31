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
 * Kanboard swimlane management tool.
 *
 * Covers swimlane CRUD, enable/disable, reordering, and bulk operations.
 */
final readonly class SwimlaneTool
{
    private const array ACTIONS = [
        'list_active', 'list_all', 'get', 'get_by_name',
        'create', 'update', 'remove', 'enable', 'disable',
        'change_position',
        'bulk_create', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_swimlane',
            description: 'Manage Kanboard swimlanes: list active/all, create, update, remove, enable/disable, reorder, and bulk operations.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new NumberParameter('swimlane_id', 'Swimlane ID', required: false, integer: true),
                new StringParameter('name', 'Swimlane name', required: false),
                new StringParameter('description', 'Swimlane description', required: false),
                new NumberParameter('position', 'Position (1-based)', required: false, integer: true, minimum: 1),
                new StringParameter('operations', 'JSON array of operations for bulk actions', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'list_active' => $this->listActive($args),
            'list_all' => $this->listAll($args),
            'get' => $this->get($args),
            'get_by_name' => $this->getByName($args),
            'create' => $this->create($args),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'enable' => $this->toggleEnabled($args, 'enableSwimlane'),
            'disable' => $this->toggleEnabled($args, 'disableSwimlane'),
            'change_position' => $this->changePosition($args),
            'bulk_create' => $this->executeBulk($args, 'addSwimlane', 'name'),
            'bulk_remove' => $this->executeBulk($args, 'removeSwimlane', 'swimlane_id'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function listActive(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for list_active.');
        }

        return $this->callApi('getActiveSwimlanes', ['project_id' => $projectId]);
    }

    private function listAll(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for list_all.');
        }

        return $this->callApi('getAllSwimlanes', ['project_id' => $projectId]);
    }

    private function get(array $args): ToolResult
    {
        $swimlaneId = $this->requireInt($args, 'swimlane_id');
        if ($swimlaneId === null) {
            return ToolResult::error('swimlane_id is required for get.');
        }

        return $this->callApi('getSwimlaneById', ['swimlane_id' => $swimlaneId]);
    }

    private function getByName(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $name = trim((string) ($args['name'] ?? ''));

        if ($projectId === null || $name === '') {
            return ToolResult::error('project_id and name are required for get_by_name.');
        }

        return $this->callApi('getSwimlaneByName', ['project_id' => $projectId, 'name' => $name]);
    }

    private function create(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $name = trim((string) ($args['name'] ?? ''));

        if ($projectId === null || $name === '') {
            return ToolResult::error('project_id and name are required for create.');
        }

        $params = ['project_id' => $projectId, 'name' => $name];
        $this->addOptionalString($params, $args, 'description');

        return $this->callApi('addSwimlane', $params);
    }

    private function update(array $args): ToolResult
    {
        $swimlaneId = $this->requireInt($args, 'swimlane_id');
        if ($swimlaneId === null) {
            return ToolResult::error('swimlane_id is required for update.');
        }

        $params = ['swimlane_id' => $swimlaneId];
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'description');

        return $this->callApi('updateSwimlane', $params);
    }

    private function remove(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $swimlaneId = $this->requireInt($args, 'swimlane_id');

        if ($projectId === null || $swimlaneId === null) {
            return ToolResult::error('project_id and swimlane_id are required for remove.');
        }

        return $this->callApi('removeSwimlane', ['project_id' => $projectId, 'swimlane_id' => $swimlaneId]);
    }

    private function toggleEnabled(array $args, string $method): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $swimlaneId = $this->requireInt($args, 'swimlane_id');

        if ($projectId === null || $swimlaneId === null) {
            return ToolResult::error('project_id and swimlane_id are required for enable/disable.');
        }

        return $this->callApi($method, ['project_id' => $projectId, 'swimlane_id' => $swimlaneId]);
    }

    private function changePosition(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $swimlaneId = $this->requireInt($args, 'swimlane_id');
        $position = $this->requireInt($args, 'position');

        if ($projectId === null || $swimlaneId === null || $position === null) {
            return ToolResult::error('project_id, swimlane_id, and position are required for change_position.');
        }

        return $this->callApi('changeSwimlanePosition', [
            'project_id' => $projectId,
            'swimlane_id' => $swimlaneId,
            'position' => $position,
        ]);
    }

    private function executeBulk(array $args, string $method, string $requiredField): ToolResult
    {
        $raw = trim((string) ($args['operations'] ?? ''));
        if ($raw === '') {
            return ToolResult::error('operations (JSON array) is required for bulk actions.');
        }

        $operations = json_decode($raw, true);
        if (!is_array($operations)) {
            return ToolResult::error('operations must be a valid JSON array.');
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || !isset($op[$requiredField])) {
                return ToolResult::error("Operation {$i}: missing required field '{$requiredField}'.");
            }
            $requests[] = ['method' => $method, 'params' => $op];
        }

        try {
            $results = $this->client->batch($requests);

            return ToolResult::success(
                json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
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
