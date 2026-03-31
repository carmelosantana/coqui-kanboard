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
 * Kanboard task file management tool.
 *
 * Covers file attachment CRUD on tasks.
 * Upload uses base64 encoding; download returns base64.
 */
final readonly class TaskFileTool
{
    private const array ACTIONS = [
        'create', 'list', 'get', 'download', 'remove', 'remove_all',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_task_file',
            description: 'Manage file attachments on Kanboard tasks: upload (base64), list, get info, download (base64), remove, and remove all.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('task_id', 'Task ID', required: false, integer: true),
                new NumberParameter('file_id', 'File ID', required: false, integer: true),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new StringParameter('filename', 'Filename', required: false),
                new StringParameter('blob', 'Base64-encoded file content for upload', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'create' => $this->create($args),
            'list' => $this->list($args),
            'get' => $this->get($args),
            'download' => $this->download($args),
            'remove' => $this->remove($args),
            'remove_all' => $this->removeAll($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function create(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $taskId = $this->requireInt($args, 'task_id');
        $filename = trim((string) ($args['filename'] ?? ''));
        $blob = trim((string) ($args['blob'] ?? ''));

        if ($projectId === null || $taskId === null || $filename === '' || $blob === '') {
            return ToolResult::error('project_id, task_id, filename, and blob (base64) are required for create.');
        }

        return $this->callApi('createTaskFile', [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'filename' => $filename,
            'blob' => $blob,
        ]);
    }

    private function list(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for list.');
        }

        return $this->callApi('getAllTaskFiles', ['task_id' => $taskId]);
    }

    private function get(array $args): ToolResult
    {
        $fileId = $this->requireInt($args, 'file_id');
        if ($fileId === null) {
            return ToolResult::error('file_id is required for get.');
        }

        return $this->callApi('getTaskFile', ['file_id' => $fileId]);
    }

    private function download(array $args): ToolResult
    {
        $fileId = $this->requireInt($args, 'file_id');
        if ($fileId === null) {
            return ToolResult::error('file_id is required for download.');
        }

        return $this->callApi('downloadTaskFile', ['file_id' => $fileId]);
    }

    private function remove(array $args): ToolResult
    {
        $fileId = $this->requireInt($args, 'file_id');
        if ($fileId === null) {
            return ToolResult::error('file_id is required for remove.');
        }

        return $this->callApi('removeTaskFile', ['file_id' => $fileId]);
    }

    private function removeAll(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for remove_all.');
        }

        return $this->callApi('removeAllTaskFiles', ['task_id' => $taskId]);
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
