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
 * Kanboard tag management tool.
 *
 * Covers project-level and task-level tag operations.
 * Tags can be scoped to a project or assigned to tasks.
 */
final readonly class TagTool
{
    private const int MAX_BULK_SIZE = 50;

    private const array ACTIONS = [
        'list_all', 'list_by_project', 'create', 'update', 'remove',
        'get_task_tags', 'set_task_tags',
        'bulk_set_task_tags',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_tag',
            description: 'Manage Kanboard tags: list all/by project, create, update, remove, get/set task tags, and bulk tag assignment.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new NumberParameter('tag_id', 'Tag ID', required: false, integer: true),
                new NumberParameter('task_id', 'Task ID (for task tag operations)', required: false, integer: true),
                new StringParameter('name', 'Tag name', required: false),
                new StringParameter('color_id', 'Color identifier', required: false),
                new StringParameter('tags', 'JSON array of tag names for set_task_tags', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'list_all' => $this->callApi('getAllTags', []),
            'list_by_project' => $this->listByProject($args),
            'create' => $this->create($args),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'get_task_tags' => $this->getTaskTags($args),
            'set_task_tags' => $this->setTaskTags($args),
            'bulk_set_task_tags' => $this->bulkSetTaskTags($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function listByProject(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for list_by_project.');
        }

        return $this->callApi('getTagsByProject', ['project_id' => $projectId]);
    }

    private function create(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $name = trim((string) ($args['name'] ?? ''));

        if ($projectId === null || $name === '') {
            return ToolResult::error('project_id and name are required for create.');
        }

        $params = ['project_id' => $projectId, 'name' => $name];
        $this->addOptionalString($params, $args, 'color_id');

        return $this->callApi('createTag', $params);
    }

    private function update(array $args): ToolResult
    {
        $tagId = $this->requireInt($args, 'tag_id');
        if ($tagId === null) {
            return ToolResult::error('tag_id is required for update.');
        }

        $params = ['tag_id' => $tagId];
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'color_id');

        return $this->callApi('updateTag', $params);
    }

    private function remove(array $args): ToolResult
    {
        $tagId = $this->requireInt($args, 'tag_id');
        if ($tagId === null) {
            return ToolResult::error('tag_id is required for remove.');
        }

        return $this->callApi('removeTag', ['tag_id' => $tagId]);
    }

    private function getTaskTags(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for get_task_tags.');
        }

        return $this->callApi('getTaskTags', ['task_id' => $taskId]);
    }

    private function setTaskTags(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $taskId = $this->requireInt($args, 'task_id');
        $tagsRaw = trim((string) ($args['tags'] ?? ''));

        if ($projectId === null || $taskId === null || $tagsRaw === '') {
            return ToolResult::error('project_id, task_id, and tags (JSON array of tag names) are required for set_task_tags.');
        }

        $tags = json_decode($tagsRaw, true);
        if (!is_array($tags)) {
            return ToolResult::error('tags must be a valid JSON array of tag name strings.');
        }

        return $this->callApi('setTaskTags', [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'tags' => $tags,
        ]);
    }

    private function bulkSetTaskTags(array $args): ToolResult
    {
        $raw = trim((string) ($args['operations'] ?? ''));
        if ($raw === '') {
            return ToolResult::error('operations (JSON array) is required for bulk_set_task_tags. Each item needs project_id, task_id, and tags.');
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
            if (!is_array($op) || !isset($op['project_id'], $op['task_id'], $op['tags'])) {
                return ToolResult::error("Operation {$i}: missing required fields (project_id, task_id, tags).");
            }
            $requests[] = ['method' => 'setTaskTags', 'params' => $op];
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
