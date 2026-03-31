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
 * Kanboard comment management tool.
 *
 * Covers comment CRUD and bulk operations on tasks.
 * Comments support Markdown content.
 */
final readonly class CommentTool
{
    private const array ACTIONS = [
        'create', 'get', 'list', 'update', 'remove',
        'bulk_create', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_comment',
            description: 'Manage Kanboard comments on tasks: create, read, update, delete, and bulk operations. Content supports Markdown.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('comment_id', 'Comment ID', required: false, integer: true),
                new NumberParameter('task_id', 'Task ID', required: false, integer: true),
                new NumberParameter('user_id', 'Author user ID', required: false, integer: true),
                new StringParameter('content', 'Comment content (Markdown supported)', required: false),
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
            'bulk_create' => $this->executeBulk($args, 'createComment', 'content'),
            'bulk_remove' => $this->executeBulk($args, 'removeComment', 'comment_id'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function create(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        $userId = $this->requireInt($args, 'user_id');
        $content = trim((string) ($args['content'] ?? ''));

        if ($taskId === null || $userId === null || $content === '') {
            return ToolResult::error('task_id, user_id, and content are required for create.');
        }

        return $this->callApi('createComment', [
            'task_id' => $taskId,
            'user_id' => $userId,
            'content' => $content,
        ]);
    }

    private function get(array $args): ToolResult
    {
        $commentId = $this->requireInt($args, 'comment_id');
        if ($commentId === null) {
            return ToolResult::error('comment_id is required for get.');
        }

        return $this->callApi('getComment', ['comment_id' => $commentId]);
    }

    private function list(array $args): ToolResult
    {
        $taskId = $this->requireInt($args, 'task_id');
        if ($taskId === null) {
            return ToolResult::error('task_id is required for list.');
        }

        return $this->callApi('getAllComments', ['task_id' => $taskId]);
    }

    private function update(array $args): ToolResult
    {
        $commentId = $this->requireInt($args, 'comment_id');
        $content = trim((string) ($args['content'] ?? ''));

        if ($commentId === null || $content === '') {
            return ToolResult::error('comment_id and content are required for update.');
        }

        return $this->callApi('updateComment', [
            'id' => $commentId,
            'content' => $content,
        ]);
    }

    private function remove(array $args): ToolResult
    {
        $commentId = $this->requireInt($args, 'comment_id');
        if ($commentId === null) {
            return ToolResult::error('comment_id is required for remove.');
        }

        return $this->callApi('removeComment', ['comment_id' => $commentId]);
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
}
