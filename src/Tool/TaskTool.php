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
 * Kanboard task management tool.
 *
 * Covers all task CRUD operations plus search, overdue detection, task
 * movement between columns/projects, duplication, and bulk operations.
 */
final readonly class TaskTool
{
    private const int MAX_BULK_SIZE = 50;

    private const array ACTIONS = [
        'create', 'get', 'get_by_reference', 'list', 'search',
        'get_overdue', 'get_overdue_by_project',
        'update', 'open', 'close', 'remove',
        'move_position', 'move_to_project', 'duplicate_to_project',
        'bulk_create', 'bulk_update', 'bulk_move', 'bulk_close', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_task',
            description: 'Manage Kanboard tasks: create, read, update, delete, search, move between columns/projects, detect overdue, and bulk operations.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('task_id', 'Task ID', required: false, integer: true),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new StringParameter('title', 'Task title', required: false),
                new StringParameter('description', 'Task description (Markdown)', required: false),
                new NumberParameter('column_id', 'Column ID for placement', required: false, integer: true),
                new NumberParameter('swimlane_id', 'Swimlane ID', required: false, integer: true),
                new NumberParameter('owner_id', 'Assignee user ID', required: false, integer: true),
                new NumberParameter('creator_id', 'Creator user ID', required: false, integer: true),
                new NumberParameter('category_id', 'Category ID', required: false, integer: true),
                new StringParameter('date_due', 'Due date (YYYY-MM-DD)', required: false),
                new StringParameter('date_started', 'Start date (YYYY-MM-DD)', required: false),
                new StringParameter('color_id', 'Color (yellow, blue, green, orange, red, purple, grey, brown, deep_orange, dark_grey, teal, lime, light_green, amber)', required: false),
                new NumberParameter('score', 'Complexity score', required: false, integer: true),
                new NumberParameter('priority', 'Task priority', required: false, integer: true),
                new StringParameter('reference', 'External reference', required: false),
                new StringParameter('tags', 'JSON array of tag names', required: false),
                new StringParameter('query', 'Search query for search action (e.g. "assignee:nobody status:open")', required: false),
                new NumberParameter('status_id', 'Status filter for list (1=active, 0=inactive)', required: false, integer: true),
                new NumberParameter('position', 'Position for move_position', required: false, integer: true),
                new NumberParameter('recurrence_status', 'Recurrence status (0=none, 1=pending, 2=processed)', required: false, integer: true),
                new NumberParameter('recurrence_trigger', 'Recurrence trigger (0=first column, 1=last column, 2=close)', required: false, integer: true),
                new NumberParameter('recurrence_factor', 'Recurrence factor (multiplier for timeframe)', required: false, integer: true),
                new NumberParameter('recurrence_timeframe', 'Recurrence timeframe (0=days, 1=months, 2=years)', required: false, integer: true),
                new NumberParameter('recurrence_basedate', 'Recurrence base date (0=due date, 1=creation date)', required: false, integer: true),
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
            'get_by_reference' => $this->getByReference($args),
            'list' => $this->list($args),
            'search' => $this->search($args),
            'get_overdue' => $this->callApi('getOverdueTasks'),
            'get_overdue_by_project' => $this->getOverdueByProject($args),
            'update' => $this->update($args),
            'open' => $this->simpleTaskAction($args, 'openTask'),
            'close' => $this->simpleTaskAction($args, 'closeTask'),
            'remove' => $this->simpleTaskAction($args, 'removeTask'),
            'move_position' => $this->movePosition($args),
            'move_to_project' => $this->moveToProject($args),
            'duplicate_to_project' => $this->duplicateToProject($args),
            'bulk_create' => $this->bulkCreate($args),
            'bulk_update' => $this->bulkUpdate($args),
            'bulk_move' => $this->bulkMove($args),
            'bulk_close' => $this->bulkClose($args),
            'bulk_remove' => $this->bulkRemove($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function create(array $args): ToolResult
    {
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            return ToolResult::error('title is required for create.');
        }

        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for create.');
        }

        $params = ['title' => $title, 'project_id' => $projectId];
        $this->addOptionalString($params, $args, 'description');
        $this->addOptionalString($params, $args, 'color_id');
        $this->addOptionalInt($params, $args, 'column_id');
        $this->addOptionalInt($params, $args, 'owner_id');
        $this->addOptionalInt($params, $args, 'creator_id');
        $this->addOptionalInt($params, $args, 'category_id');
        $this->addOptionalInt($params, $args, 'swimlane_id');
        $this->addOptionalInt($params, $args, 'score');
        $this->addOptionalInt($params, $args, 'priority');
        $this->addOptionalString($params, $args, 'date_due');
        $this->addOptionalString($params, $args, 'date_started');
        $this->addOptionalString($params, $args, 'reference');
        $this->addOptionalInt($params, $args, 'recurrence_status');
        $this->addOptionalInt($params, $args, 'recurrence_trigger');
        $this->addOptionalInt($params, $args, 'recurrence_factor');
        $this->addOptionalInt($params, $args, 'recurrence_timeframe');
        $this->addOptionalInt($params, $args, 'recurrence_basedate');

        $tags = $this->parseJsonArray($args, 'tags');
        if ($tags !== null) {
            $params['tags'] = $tags;
        }

        return $this->callApi('createTask', $params);
    }

    private function get(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for get.');
        }

        return $this->callApi('getTask', ['task_id' => $taskId]);
    }

    private function getByReference(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $reference = trim((string) ($args['reference'] ?? ''));

        if ($projectId === null || $reference === '') {
            return ToolResult::error('project_id and reference are required for get_by_reference.');
        }

        return $this->callApi('getTaskByReference', [
            'project_id' => $projectId,
            'reference' => $reference,
        ]);
    }

    private function list(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for list.');
        }

        $statusId = $this->requireInt($args, 'status_id') ?? 1;

        return $this->callApi('getAllTasks', [
            'project_id' => $projectId,
            'status_id' => $statusId,
        ]);
    }

    private function search(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $query = trim((string) ($args['query'] ?? ''));

        if ($projectId === null || $query === '') {
            return ToolResult::error('project_id and query are required for search.');
        }

        return $this->callApi('searchTasks', [
            'project_id' => $projectId,
            'query' => $query,
        ]);
    }

    private function getOverdueByProject(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for get_overdue_by_project.');
        }

        return $this->callApi('getOverdueTasksByProject', ['project_id' => $projectId]);
    }

    private function update(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for update.');
        }

        $params = ['id' => $taskId];
        $this->addOptionalString($params, $args, 'title');
        $this->addOptionalString($params, $args, 'description');
        $this->addOptionalString($params, $args, 'color_id');
        $this->addOptionalInt($params, $args, 'column_id');
        $this->addOptionalInt($params, $args, 'owner_id');
        $this->addOptionalInt($params, $args, 'creator_id');
        $this->addOptionalInt($params, $args, 'category_id');
        $this->addOptionalInt($params, $args, 'swimlane_id');
        $this->addOptionalInt($params, $args, 'score');
        $this->addOptionalInt($params, $args, 'priority');
        $this->addOptionalString($params, $args, 'date_due');
        $this->addOptionalString($params, $args, 'date_started');
        $this->addOptionalString($params, $args, 'reference');
        $this->addOptionalInt($params, $args, 'recurrence_status');
        $this->addOptionalInt($params, $args, 'recurrence_trigger');
        $this->addOptionalInt($params, $args, 'recurrence_factor');
        $this->addOptionalInt($params, $args, 'recurrence_timeframe');
        $this->addOptionalInt($params, $args, 'recurrence_basedate');

        $tags = $this->parseJsonArray($args, 'tags');
        if ($tags !== null) {
            $params['tags'] = $tags;
        }

        return $this->callApi('updateTask', $params);
    }

    private function simpleTaskAction(array $args, string $method): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required.');
        }

        return $this->callApi($method, ['task_id' => $taskId]);
    }

    private function movePosition(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $projectId = $this->requireInt($args, 'project_id');
        $columnId = $this->requireInt($args, 'column_id');
        $position = $this->requireInt($args, 'position');
        $swimlaneId = $this->requireInt($args, 'swimlane_id');

        if ($taskId === null || $projectId === null || $columnId === null || $position === null || $swimlaneId === null) {
            return ToolResult::error('task_id, project_id, column_id, position, and swimlane_id are all required for move_position.');
        }

        return $this->callApi('moveTaskPosition', [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'column_id' => $columnId,
            'position' => $position,
            'swimlane_id' => $swimlaneId,
        ]);
    }

    private function moveToProject(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $projectId = $this->requireInt($args, 'project_id');

        if ($taskId === null || $projectId === null) {
            return ToolResult::error('task_id and project_id are required for move_to_project.');
        }

        $params = ['task_id' => $taskId, 'project_id' => $projectId];
        $this->addOptionalInt($params, $args, 'swimlane_id');
        $this->addOptionalInt($params, $args, 'column_id');
        $this->addOptionalInt($params, $args, 'category_id');
        $this->addOptionalInt($params, $args, 'owner_id');

        return $this->callApi('moveTaskToProject', $params);
    }

    private function duplicateToProject(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $projectId = $this->requireInt($args, 'project_id');

        if ($taskId === null || $projectId === null) {
            return ToolResult::error('task_id and project_id are required for duplicate_to_project.');
        }

        $params = ['task_id' => $taskId, 'project_id' => $projectId];
        $this->addOptionalInt($params, $args, 'swimlane_id');
        $this->addOptionalInt($params, $args, 'column_id');
        $this->addOptionalInt($params, $args, 'category_id');
        $this->addOptionalInt($params, $args, 'owner_id');

        return $this->callApi('duplicateTaskToProject', $params);
    }

    private function bulkCreate(array $args): ToolResult
    {
        return $this->executeBulk($args, 'createTask', 'title');
    }

    private function bulkUpdate(array $args): ToolResult
    {
        return $this->executeBulk($args, 'updateTask', 'id');
    }

    private function bulkMove(array $args): ToolResult
    {
        return $this->executeBulk($args, 'moveTaskPosition', 'task_id');
    }

    private function bulkClose(array $args): ToolResult
    {
        return $this->executeBulk($args, 'closeTask', 'task_id');
    }

    private function bulkRemove(array $args): ToolResult
    {
        return $this->executeBulk($args, 'removeTask', 'task_id');
    }

    private function executeBulk(array $args, string $method, string $requiredField): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk actions.');
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

    private function parseJsonArray(array $args, string $key): ?array
    {
        $raw = trim((string) ($args[$key] ?? ''));
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

    private function addOptionalInt(array &$params, array $args, string $key): void
    {
        if (isset($args[$key]) && $args[$key] !== '') {
            $params[$key] = (int) $args[$key];
        }
    }
}
