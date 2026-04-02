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
 * Kanboard task link management tool.
 *
 * Combines link type management (relation definitions like "blocks", "is blocked by")
 * with internal task-to-task link CRUD. For external links (URLs), see ExternalTaskLinkTool.
 */
final readonly class TaskLinkTool
{
    private const array ACTIONS = [
        // Link type management
        'list_types', 'get_type', 'get_type_by_label', 'get_opposite',
        'create_type', 'update_type', 'remove_type',
        // Internal task links
        'create', 'get', 'list', 'update', 'remove',
        'bulk_create', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_task_link',
            description: 'Manage Kanboard task links: define link types (blocks, relates to, duplicates, etc.) and create/manage internal task-to-task links. For external URL links, use kanboard_external_task_link.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('link_id', 'Link type ID', required: false, integer: true),
                new NumberParameter('opposite_link_id', 'Opposite link type ID (for update_type)', required: false, integer: true),
                new NumberParameter('task_link_id', 'Task link instance ID', required: false, integer: true),
                new NumberParameter('task_id', 'Source task ID', required: false, integer: true),
                new NumberParameter('opposite_task_id', 'Target task ID', required: false, integer: true),
                new StringParameter('label', 'Link type label (e.g. "blocks")', required: false),
                new StringParameter('opposite_label', 'Opposite direction label (e.g. "is blocked by")', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            // Link type management
            'list_types' => $this->callApi('getAllLinks'),
            'get_type' => $this->getType($args),
            'get_type_by_label' => $this->getTypeByLabel($args),
            'get_opposite' => $this->getOpposite($args),
            'create_type' => $this->createType($args),
            'update_type' => $this->updateType($args),
            'remove_type' => $this->removeType($args),
            // Internal task links
            'create' => $this->create($args),
            'get' => $this->get($args),
            'list' => $this->list($args),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'bulk_create' => $this->bulkCreate($args),
            'bulk_remove' => $this->executeBulk($args, 'removeTaskLink', 'task_link_id'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    // --- Link type management ---

    private function getType(array $args): ToolResult
    {
        $linkId = $this->requireInt($args, 'link_id');
        if ($linkId === null) {
            return ToolResult::error('link_id is required for get_type.');
        }

        return $this->callApi('getLinkById', ['link_id' => $linkId]);
    }

    private function getTypeByLabel(array $args): ToolResult
    {
        $label = trim((string) ($args['label'] ?? ''));
        if ($label === '') {
            return ToolResult::error('label is required for get_type_by_label.');
        }

        return $this->callApi('getLinkByLabel', ['label' => $label]);
    }

    private function getOpposite(array $args): ToolResult
    {
        $linkId = $this->requireInt($args, 'link_id');
        if ($linkId === null) {
            return ToolResult::error('link_id is required for get_opposite.');
        }

        return $this->callApi('getOppositeLinkId', ['link_id' => $linkId]);
    }

    private function createType(array $args): ToolResult
    {
        $label = trim((string) ($args['label'] ?? ''));
        if ($label === '') {
            return ToolResult::error('label is required for create_type.');
        }

        $params = ['label' => $label];
        $this->addOptionalString($params, $args, 'opposite_label');

        return $this->callApi('createLink', $params);
    }

    private function updateType(array $args): ToolResult
    {
        $linkId = $this->requireInt($args, 'link_id');
        $oppositeLinkId = $this->requireInt($args, 'opposite_link_id');
        $label = trim((string) ($args['label'] ?? ''));

        if ($linkId === null || $oppositeLinkId === null || $label === '') {
            return ToolResult::error('link_id, opposite_link_id, and label are required for update_type.');
        }

        return $this->callApi('updateLink', [
            'link_id' => $linkId,
            'opposite_link_id' => $oppositeLinkId,
            'label' => $label,
        ]);
    }

    private function removeType(array $args): ToolResult
    {
        $linkId = $this->requireInt($args, 'link_id');
        if ($linkId === null) {
            return ToolResult::error('link_id is required for remove_type.');
        }

        return $this->callApi('removeLink', ['link_id' => $linkId]);
    }

    // --- Internal task links ---

    private function create(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $oppositeTaskId = $this->requireInt($args, 'opposite_task_id');
        $linkId = $this->requireInt($args, 'link_id');

        if ($taskId === null || $oppositeTaskId === null || $linkId === null) {
            return ToolResult::error('task_id, opposite_task_id, and link_id are required for create.');
        }

        return $this->callApi('createTaskLink', [
            'task_id' => $taskId,
            'opposite_task_id' => $oppositeTaskId,
            'link_id' => $linkId,
        ]);
    }

    private function get(array $args): ToolResult
    {
        $taskLinkId = $this->requireInt($args, 'task_link_id');
        if ($taskLinkId === null) {
            return ToolResult::error('task_link_id is required for get.');
        }

        return $this->callApi('getTaskLinkById', ['task_link_id' => $taskLinkId]);
    }

    private function list(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for list.');
        }

        return $this->callApi('getAllTaskLinks', ['task_id' => $taskId]);
    }

    private function update(array $args): ToolResult
    {
        $taskLinkId = $this->requireInt($args, 'task_link_id');
        $taskId = $this->requireInt($args, 'task_id');
        $oppositeTaskId = $this->requireInt($args, 'opposite_task_id');
        $linkId = $this->requireInt($args, 'link_id');

        if ($taskLinkId === null || $taskId === null || $oppositeTaskId === null || $linkId === null) {
            return ToolResult::error('task_link_id, task_id, opposite_task_id, and link_id are required for update.');
        }

        return $this->callApi('updateTaskLink', [
            'task_link_id' => $taskLinkId,
            'task_id' => $taskId,
            'opposite_task_id' => $oppositeTaskId,
            'link_id' => $linkId,
        ]);
    }

    private function remove(array $args): ToolResult
    {
        $taskLinkId = $this->requireInt($args, 'task_link_id');
        if ($taskLinkId === null) {
            return ToolResult::error('task_link_id is required for remove.');
        }

        return $this->callApi('removeTaskLink', ['task_link_id' => $taskLinkId]);
    }

    private function bulkCreate(array $args): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk_create. Each item needs task_id, opposite_task_id, and link_id.');
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || !isset($op['task_id'], $op['opposite_task_id'], $op['link_id'])) {
                return ToolResult::error("Operation {$i}: missing required fields (task_id, opposite_task_id, link_id).");
            }
            $requests[] = ['method' => 'createTaskLink', 'params' => $op];
        }

        return $this->executeBatch($requests);
    }

    private function executeBulk(array $args, string $method, string $requiredField): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk actions.');
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
