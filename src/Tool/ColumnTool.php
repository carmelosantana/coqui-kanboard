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
 * Kanboard column management tool.
 *
 * Covers column CRUD, reordering, and bulk operations.
 */
final readonly class ColumnTool
{
    private const int MAX_BULK_SIZE = 50;

    private const array ACTIONS = [
        'list', 'get', 'create', 'update', 'remove', 'change_position',
        'bulk_create', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_column',
            description: 'Manage Kanboard columns: list, create, update, remove, reorder, and bulk operations.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new NumberParameter('column_id', 'Column ID', required: false, integer: true),
                new StringParameter('title', 'Column title', required: false),
                new NumberParameter('task_limit', 'WIP task limit (0 = unlimited)', required: false, integer: true, minimum: 0),
                new StringParameter('description', 'Column description', required: false),
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
            'list' => $this->list($args),
            'get' => $this->get($args),
            'create' => $this->create($args),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'change_position' => $this->changePosition($args),
            'bulk_create' => $this->executeBulk($args, 'addColumn', 'title'),
            'bulk_remove' => $this->executeBulk($args, 'removeColumn', 'column_id'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function list(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for list.');
        }

        return $this->callApi('getColumns', ['project_id' => $projectId]);
    }

    private function get(array $args): ToolResult
    {
        $columnId = $this->requireInt($args, 'column_id');
        if ($columnId === null) {
            return ToolResult::error('column_id is required for get.');
        }

        return $this->callApi('getColumn', ['column_id' => $columnId]);
    }

    private function create(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $title = trim((string) ($args['title'] ?? ''));

        if ($projectId === null || $title === '') {
            return ToolResult::error('project_id and title are required for create.');
        }

        $params = ['project_id' => $projectId, 'title' => $title];
        $this->addOptionalInt($params, $args, 'task_limit');
        $this->addOptionalString($params, $args, 'description');

        return $this->callApi('addColumn', $params);
    }

    private function update(array $args): ToolResult
    {
        $columnId = $this->requireInt($args, 'column_id');
        if ($columnId === null) {
            return ToolResult::error('column_id is required for update.');
        }

        $params = ['column_id' => $columnId];
        $this->addOptionalString($params, $args, 'title');
        $this->addOptionalInt($params, $args, 'task_limit');
        $this->addOptionalString($params, $args, 'description');

        return $this->callApi('updateColumn', $params);
    }

    private function remove(array $args): ToolResult
    {
        $columnId = $this->requireInt($args, 'column_id');
        if ($columnId === null) {
            return ToolResult::error('column_id is required for remove.');
        }

        return $this->callApi('removeColumn', ['column_id' => $columnId]);
    }

    private function changePosition(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $columnId = $this->requireInt($args, 'column_id');
        $position = $this->requireInt($args, 'position');

        if ($projectId === null || $columnId === null || $position === null) {
            return ToolResult::error('project_id, column_id, and position are required for change_position.');
        }

        return $this->callApi('changeColumnPosition', [
            'project_id' => $projectId,
            'column_id' => $columnId,
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

        if (count($operations) > self::MAX_BULK_SIZE) {
            return ToolResult::error(sprintf('Too many operations (%d). Maximum is %d per call.', count($operations), self::MAX_BULK_SIZE));
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

    private function addOptionalInt(array &$params, array $args, string $key): void
    {
        if (isset($args[$key]) && $args[$key] !== '') {
            $params[$key] = (int) $args[$key];
        }
    }
}
