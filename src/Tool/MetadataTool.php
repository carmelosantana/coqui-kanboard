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
 * Kanboard metadata management tool.
 *
 * Covers project metadata and task metadata: get all, get by name, save, and remove.
 * Metadata is arbitrary key-value storage on projects and tasks.
 */
final readonly class MetadataTool
{
    private const array ACTIONS = [
        // Project metadata
        'get_project', 'get_project_by_name', 'save_project', 'remove_project',
        // Task metadata
        'get_task', 'get_task_by_name', 'save_task', 'remove_task',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_metadata',
            description: 'Manage Kanboard metadata on projects and tasks: get all, get by name, save (add/update), and remove key-value pairs. Metadata provides arbitrary custom storage.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID (for project metadata actions)', required: false, integer: true),
                new NumberParameter('task_id', 'Task ID (for task metadata actions)', required: false, integer: true),
                new StringParameter('name', 'Metadata key name', required: false),
                new StringParameter('values', 'JSON object of key-value pairs for save (e.g. {"key1": "value1", "key2": "value2"})', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'get_project' => $this->getProject($args),
            'get_project_by_name' => $this->getProjectByName($args),
            'save_project' => $this->saveProject($args),
            'remove_project' => $this->removeProject($args),
            'get_task' => $this->getTask($args),
            'get_task_by_name' => $this->getTaskByName($args),
            'save_task' => $this->saveTask($args),
            'remove_task' => $this->removeTask($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    // --- Project metadata ---

    private function getProject(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for get_project.');
        }

        return $this->callApi('getProjectMetadata', ['project_id' => $projectId]);
    }

    private function getProjectByName(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $name = trim((string) ($args['name'] ?? ''));

        if ($projectId === null || $name === '') {
            return ToolResult::error('project_id and name are required for get_project_by_name.');
        }

        return $this->callApi('getProjectMetadataByName', [
            'project_id' => $projectId,
            'name' => $name,
        ]);
    }

    private function saveProject(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $valuesRaw = trim((string) ($args['values'] ?? ''));

        if ($projectId === null || $valuesRaw === '') {
            return ToolResult::error('project_id and values (JSON object) are required for save_project.');
        }

        $values = json_decode($valuesRaw, true);
        if (!is_array($values)) {
            return ToolResult::error('values must be a valid JSON object of key-value pairs.');
        }

        return $this->callApi('saveProjectMetadata', [
            'project_id' => $projectId,
            'values' => $values,
        ]);
    }

    private function removeProject(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $name = trim((string) ($args['name'] ?? ''));

        if ($projectId === null || $name === '') {
            return ToolResult::error('project_id and name are required for remove_project.');
        }

        return $this->callApi('removeProjectMetadata', [
            'project_id' => $projectId,
            'name' => $name,
        ]);
    }

    // --- Task metadata ---

    private function getTask(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for get_task.');
        }

        return $this->callApi('getTaskMetadata', ['task_id' => $taskId]);
    }

    private function getTaskByName(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $name = trim((string) ($args['name'] ?? ''));

        if ($taskId === null || $name === '') {
            return ToolResult::error('task_id and name are required for get_task_by_name.');
        }

        return $this->callApi('getTaskMetadataByName', [
            'task_id' => $taskId,
            'name' => $name,
        ]);
    }

    private function saveTask(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $valuesRaw = trim((string) ($args['values'] ?? ''));

        if ($taskId === null || $valuesRaw === '') {
            return ToolResult::error('task_id and values (JSON object) are required for save_task.');
        }

        $values = json_decode($valuesRaw, true);
        if (!is_array($values)) {
            return ToolResult::error('values must be a valid JSON object of key-value pairs.');
        }

        return $this->callApi('saveTaskMetadata', [
            'task_id' => $taskId,
            'values' => $values,
        ]);
    }

    private function removeTask(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $name = trim((string) ($args['name'] ?? ''));

        if ($taskId === null || $name === '') {
            return ToolResult::error('task_id and name are required for remove_task.');
        }

        return $this->callApi('removeTaskMetadata', [
            'task_id' => $taskId,
            'name' => $name,
        ]);
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
}
