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
 * Kanboard external task link management tool.
 *
 * Covers linking tasks to external URLs (websites, issue trackers, documents)
 * with dependency/relationship tracking. For internal task-to-task links, see TaskLinkTool.
 */
final readonly class ExternalTaskLinkTool
{
    private const array ACTIONS = [
        'list_types', 'get_dependencies',
        'create', 'get', 'list', 'update', 'remove',
        'bulk_create', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_external_task_link',
            description: 'Manage Kanboard external task links: link tasks to URLs (websites, issue trackers, documents) with dependency tracking. List available providers and their dependencies.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('task_id', 'Task ID', required: false, integer: true),
                new NumberParameter('link_id', 'External link ID', required: false, integer: true),
                new StringParameter('url', 'External URL', required: false),
                new StringParameter('title', 'Link title', required: false),
                new StringParameter('dependency', 'Dependency type (e.g. related, blocked, child)', required: false),
                new StringParameter('type', 'Link provider type (e.g. auto, weblink, attachment)', required: false),
                new StringParameter('provider_name', 'Provider name for get_dependencies', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'list_types' => $this->callApi('getExternalTaskLinkTypes'),
            'get_dependencies' => $this->getDependencies($args),
            'create' => $this->create($args),
            'get' => $this->get($args),
            'list' => $this->list($args),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'bulk_create' => $this->bulkCreate($args),
            'bulk_remove' => $this->bulkRemove($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function getDependencies(array $args): ToolResult
    {
        $providerName = trim((string) ($args['provider_name'] ?? ''));
        if ($providerName === '') {
            return ToolResult::error('provider_name is required for get_dependencies.');
        }

        return $this->callApi('getExternalTaskLinkProviderDependencies', [
            'providerName' => $providerName,
        ]);
    }

    private function create(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $url = trim((string) ($args['url'] ?? ''));
        $dependency = trim((string) ($args['dependency'] ?? ''));

        if ($taskId === null || $url === '' || $dependency === '') {
            return ToolResult::error('task_id, url, and dependency are required for create.');
        }

        $params = [
            'task_id' => $taskId,
            'url' => $url,
            'dependency' => $dependency,
        ];
        $this->addOptionalString($params, $args, 'type');
        $this->addOptionalString($params, $args, 'title');

        return $this->callApi('createExternalTaskLink', $params);
    }

    private function get(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $linkId = $this->requireInt($args, 'link_id');

        if ($taskId === null || $linkId === null) {
            return ToolResult::error('task_id and link_id are required for get.');
        }

        return $this->callApi('getExternalTaskLinkById', [
            'task_id' => $taskId,
            'link_id' => $linkId,
        ]);
    }

    private function list(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for list.');
        }

        return $this->callApi('getAllExternalTaskLinks', ['task_id' => $taskId]);
    }

    private function update(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $linkId = $this->requireInt($args, 'link_id');
        $title = trim((string) ($args['title'] ?? ''));
        $url = trim((string) ($args['url'] ?? ''));

        if ($taskId === null || $linkId === null || $title === '' || $url === '') {
            return ToolResult::error('task_id, link_id, title, and url are required for update.');
        }

        $params = [
            'task_id' => $taskId,
            'link_id' => $linkId,
            'title' => $title,
            'url' => $url,
        ];
        $this->addOptionalString($params, $args, 'dependency');

        return $this->callApi('updateExternalTaskLink', $params);
    }

    private function remove(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $linkId = $this->requireInt($args, 'link_id');

        if ($taskId === null || $linkId === null) {
            return ToolResult::error('task_id and link_id are required for remove.');
        }

        return $this->callApi('removeExternalTaskLink', [
            'task_id' => $taskId,
            'link_id' => $linkId,
        ]);
    }

    private function bulkCreate(array $args): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk_create. Each item needs task_id, url, and dependency.');
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || !isset($op['task_id'], $op['url'], $op['dependency'])) {
                return ToolResult::error("Operation {$i}: missing required fields (task_id, url, dependency).");
            }
            $requests[] = ['method' => 'createExternalTaskLink', 'params' => $op];
        }

        return $this->executeBatch($requests);
    }

    private function bulkRemove(array $args): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk_remove. Each item needs task_id and link_id.');
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || !isset($op['task_id'], $op['link_id'])) {
                return ToolResult::error("Operation {$i}: missing required fields (task_id, link_id).");
            }
            $requests[] = ['method' => 'removeExternalTaskLink', 'params' => $op];
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
