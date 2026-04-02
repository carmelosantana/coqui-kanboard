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
 * Kanboard project file attachment tool.
 *
 * Covers uploading, listing, downloading, and removing files attached to projects.
 * File content is handled as base64-encoded strings.
 */
final readonly class ProjectFileTool
{
    private const array ACTIONS = [
        'create', 'get', 'list', 'download', 'remove',
        'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_project_file',
            description: 'Manage Kanboard project file attachments: upload (base64), list, get info, download (base64), remove, and bulk remove.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new NumberParameter('file_id', 'File ID', required: false, integer: true),
                new StringParameter('filename', 'File name for upload', required: false),
                new StringParameter('blob', 'Base64-encoded file content for upload', required: false),
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
            'download' => $this->download($args),
            'remove' => $this->remove($args),
            'bulk_remove' => $this->bulkRemove($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function create(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $filename = trim((string) ($args['filename'] ?? ''));
        $blob = trim((string) ($args['blob'] ?? ''));

        if ($projectId === null || $filename === '' || $blob === '') {
            return ToolResult::error('project_id, filename, and blob (base64-encoded content) are required for create.');
        }

        return $this->callApi('createProjectFile', [
            'project_id' => $projectId,
            'filename' => $filename,
            'blob' => $blob,
        ]);
    }

    private function get(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $fileId = $this->requireInt($args, 'file_id');

        if ($projectId === null || $fileId === null) {
            return ToolResult::error('project_id and file_id are required for get.');
        }

        return $this->callApi('getProjectFile', [
            'project_id' => $projectId,
            'file_id' => $fileId,
        ]);
    }

    private function list(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for list.');
        }

        return $this->callApi('getAllProjectFiles', ['project_id' => $projectId]);
    }

    private function download(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $fileId = $this->requireInt($args, 'file_id');

        if ($projectId === null || $fileId === null) {
            return ToolResult::error('project_id and file_id are required for download.');
        }

        return $this->callApi('downloadProjectFile', [
            'project_id' => $projectId,
            'file_id' => $fileId,
        ]);
    }

    private function remove(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $fileId = $this->requireInt($args, 'file_id');

        if ($projectId === null || $fileId === null) {
            return ToolResult::error('project_id and file_id are required for remove.');
        }

        return $this->callApi('removeProjectFile', [
            'project_id' => $projectId,
            'file_id' => $fileId,
        ]);
    }

    private function bulkRemove(array $args): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk_remove. Each item needs project_id and file_id.');
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || !isset($op['project_id'], $op['file_id'])) {
                return ToolResult::error("Operation {$i}: missing required fields (project_id, file_id).");
            }
            $requests[] = ['method' => 'removeProjectFile', 'params' => $op];
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
}
