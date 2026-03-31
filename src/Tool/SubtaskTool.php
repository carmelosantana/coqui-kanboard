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
 * Kanboard subtask management tool.
 *
 * Covers subtask CRUD and bulk operations. Subtask status values:
 * 0 = Todo, 1 = In Progress, 2 = Done.
 */
final readonly class SubtaskTool
{
    private const array ACTIONS = [
        'create', 'get', 'list', 'update', 'remove',
        'bulk_create', 'bulk_update', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_subtask',
            description: 'Manage Kanboard subtasks: create, read, update, delete, and bulk operations. Status: 0=Todo, 1=In Progress, 2=Done.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('subtask_id', 'Subtask ID', required: false, integer: true),
                new NumberParameter('task_id', 'Parent task ID', required: false, integer: true),
                new StringParameter('title', 'Subtask title', required: false),
                new NumberParameter('user_id', 'Assigned user ID', required: false, integer: true),
                new NumberParameter('time_estimated', 'Estimated time in hours', required: false),
                new NumberParameter('time_spent', 'Time spent in hours', required: false),
                new NumberParameter('status', 'Status (0=Todo, 1=In Progress, 2=Done)', required: false, integer: true, minimum: 0, maximum: 2),
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
            'list' => $this->list($args),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'bulk_create' => $this->executeBulk($args, 'createSubtask', 'title'),
            'bulk_update' => $this->executeBulk($args, 'updateSubtask', 'id'),
            'bulk_remove' => $this->executeBulk($args, 'removeSubtask', 'subtask_id'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function create(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $title = trim((string) ($args['title'] ?? ''));

        if ($taskId === null || $title === '') {
            return ToolResult::error('task_id and title are required for create.');
        }

        $params = ['task_id' => $taskId, 'title' => $title];
        $this->addOptionalInt($params, $args, 'user_id');
        $this->addOptionalNum($params, $args, 'time_estimated');
        $this->addOptionalNum($params, $args, 'time_spent');
        $this->addOptionalInt($params, $args, 'status');

        return $this->callApi('createSubtask', $params);
    }

    private function get(array $args): ToolResult
    {
        $subtaskId = $this->requireInt($args, 'subtask_id');
        if ($subtaskId === null) {
            return ToolResult::error('subtask_id is required for get.');
        }

        return $this->callApi('getSubtask', ['subtask_id' => $subtaskId]);
    }

    private function list(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for list.');
        }

        return $this->callApi('getAllSubtasks', ['task_id' => $taskId]);
    }

    private function update(array $args): ToolResult
    {
        $subtaskId = $this->requireInt($args, 'subtask_id');
        $taskId = $this->requireInt($args, 'task_id');

        if ($subtaskId === null || $taskId === null) {
            return ToolResult::error('subtask_id and task_id are required for update.');
        }

        $params = ['id' => $subtaskId, 'task_id' => $taskId];
        $this->addOptionalString($params, $args, 'title');
        $this->addOptionalInt($params, $args, 'user_id');
        $this->addOptionalNum($params, $args, 'time_estimated');
        $this->addOptionalNum($params, $args, 'time_spent');
        $this->addOptionalInt($params, $args, 'status');

        return $this->callApi('updateSubtask', $params);
    }

    private function remove(array $args): ToolResult
    {
        $subtaskId = $this->requireInt($args, 'subtask_id');
        if ($subtaskId === null) {
            return ToolResult::error('subtask_id is required for remove.');
        }

        return $this->callApi('removeSubtask', ['subtask_id' => $subtaskId]);
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

    private function addOptionalInt(array &$params, array $args, string $key): void
    {
        if (isset($args[$key]) && $args[$key] !== '') {
            $params[$key] = (int) $args[$key];
        }
    }

    private function addOptionalNum(array &$params, array $args, string $key): void
    {
        if (isset($args[$key]) && $args[$key] !== '') {
            $params[$key] = (float) $args[$key];
        }
    }
}
