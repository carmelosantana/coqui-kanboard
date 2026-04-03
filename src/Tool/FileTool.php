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
 * Kanboard file attachment tool.
 *
 * Unified tool for both project-level and task-level file attachments.
 * File content is handled as base64-encoded strings.
 */
final readonly class FileTool
{
    private const int MAX_BULK_SIZE = 50;

    private const array ACTIONS = [
        // Project files
        'project_create', 'project_get', 'project_list', 'project_download',
        'project_remove', 'project_remove_all',
        // Task files
        'task_create', 'task_get', 'task_list', 'task_download',
        'task_remove', 'task_remove_all',
        // Bulk operations
        'bulk_project_remove', 'bulk_task_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_file',
            description: 'Manage Kanboard file attachments on projects and tasks: upload (base64), list, get info, download (base64), remove, and remove all. Actions prefixed with project_ or task_ to indicate scope.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new NumberParameter('task_id', 'Task ID', required: false, integer: true),
                new NumberParameter('file_id', 'File ID', required: false, integer: true),
                new StringParameter('filename', 'File name for upload', required: false),
                new StringParameter('blob', 'Base64-encoded file content for upload', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions (max 50)', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            // Project files
            'project_create' => $this->projectCreate($args),
            'project_get' => $this->projectGet($args),
            'project_list' => $this->projectList($args),
            'project_download' => $this->projectDownload($args),
            'project_remove' => $this->projectRemove($args),
            'project_remove_all' => $this->projectRemoveAll($args),
            // Task files
            'task_create' => $this->taskCreate($args),
            'task_get' => $this->taskGet($args),
            'task_list' => $this->taskList($args),
            'task_download' => $this->taskDownload($args),
            'task_remove' => $this->taskRemove($args),
            'task_remove_all' => $this->taskRemoveAll($args),
            // Bulk
            'bulk_project_remove' => $this->bulkRemove($args, 'removeProjectFile', ['project_id', 'file_id']),
            'bulk_task_remove' => $this->bulkRemove($args, 'removeTaskFile', ['file_id']),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    // --- Project files ---

    private function projectCreate(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $filename = trim((string) ($args['filename'] ?? ''));
        $blob = trim((string) ($args['blob'] ?? ''));

        if ($projectId === null || $filename === '' || $blob === '') {
            return ToolResult::error('project_id, filename, and blob (base64) are required for project_create.');
        }

        return $this->callApi('createProjectFile', [
            'project_id' => $projectId,
            'filename' => $filename,
            'blob' => $blob,
        ]);
    }

    private function projectGet(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $fileId = $this->requireInt($args, 'file_id');

        if ($projectId === null || $fileId === null) {
            return ToolResult::error('project_id and file_id are required for project_get.');
        }

        return $this->callApi('getProjectFile', [
            'project_id' => $projectId,
            'file_id' => $fileId,
        ]);
    }

    private function projectList(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for project_list.');
        }

        return $this->callApi('getAllProjectFiles', ['project_id' => $projectId]);
    }

    private function projectDownload(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $fileId = $this->requireInt($args, 'file_id');

        if ($projectId === null || $fileId === null) {
            return ToolResult::error('project_id and file_id are required for project_download.');
        }

        return $this->callApi('downloadProjectFile', [
            'project_id' => $projectId,
            'file_id' => $fileId,
        ]);
    }

    private function projectRemove(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $fileId = $this->requireInt($args, 'file_id');

        if ($projectId === null || $fileId === null) {
            return ToolResult::error('project_id and file_id are required for project_remove.');
        }

        return $this->callApi('removeProjectFile', [
            'project_id' => $projectId,
            'file_id' => $fileId,
        ]);
    }

    private function projectRemoveAll(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for project_remove_all.');
        }

        return $this->callApi('removeAllProjectFiles', ['project_id' => $projectId]);
    }

    // --- Task files ---

    private function taskCreate(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $taskId = $this->requireInt($args, 'task_id');
        $filename = trim((string) ($args['filename'] ?? ''));
        $blob = trim((string) ($args['blob'] ?? ''));

        if ($projectId === null || $taskId === null || $filename === '' || $blob === '') {
            return ToolResult::error('project_id, task_id, filename, and blob (base64) are required for task_create.');
        }

        return $this->callApi('createTaskFile', [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'filename' => $filename,
            'blob' => $blob,
        ]);
    }

    private function taskGet(array $args): ToolResult
    {
        $fileId = $this->requireInt($args, 'file_id');
        if ($fileId === null) {
            return ToolResult::error('file_id is required for task_get.');
        }

        return $this->callApi('getTaskFile', ['file_id' => $fileId]);
    }

    private function taskList(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for task_list.');
        }

        return $this->callApi('getAllTaskFiles', ['task_id' => $taskId]);
    }

    private function taskDownload(array $args): ToolResult
    {
        $fileId = $this->requireInt($args, 'file_id');
        if ($fileId === null) {
            return ToolResult::error('file_id is required for task_download.');
        }

        return $this->callApi('downloadTaskFile', ['file_id' => $fileId]);
    }

    private function taskRemove(array $args): ToolResult
    {
        $fileId = $this->requireInt($args, 'file_id');
        if ($fileId === null) {
            return ToolResult::error('file_id is required for task_remove.');
        }

        return $this->callApi('removeTaskFile', ['file_id' => $fileId]);
    }

    private function taskRemoveAll(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for task_remove_all.');
        }

        return $this->callApi('removeAllTaskFiles', ['task_id' => $taskId]);
    }

    // --- Bulk ---

    /**
     * @param string[] $requiredFields
     */
    private function bulkRemove(array $args, string $method, array $requiredFields): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk actions.');
        }

        if (count($operations) > self::MAX_BULK_SIZE) {
            return ToolResult::error(sprintf('Bulk operations capped at %d items. Got %d.', self::MAX_BULK_SIZE, count($operations)));
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op)) {
                return ToolResult::error("Operation {$i}: must be an object.");
            }
            foreach ($requiredFields as $field) {
                if (!isset($op[$field])) {
                    return ToolResult::error("Operation {$i}: missing required field '{$field}'.");
                }
            }
            $requests[] = ['method' => $method, 'params' => $op];
        }

        return $this->executeBatch($requests);
    }

    // --- Helpers ---

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
}
