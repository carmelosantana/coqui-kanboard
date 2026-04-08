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
 * Kanboard automatic action management tool.
 *
 * Covers listing available actions and events, creating/removing project
 * actions, and bulk operations. Actions automate board workflows
 * (e.g. auto-close tasks, auto-assign, send emails on events).
 */
final readonly class ActionTool
{
    private const array ACTIONS = [
        'list_available', 'list_events', 'get_compatible_events',
        'get_project_actions', 'create', 'remove',
        'bulk_create', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_action',
            description: 'Manage Kanboard automatic actions: list available actions and events, get compatible events for an action, create/remove project actions, and bulk operations. Actions automate workflows like auto-close, auto-assign, and email notifications.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new NumberParameter('action_id', 'Action ID (for remove)', required: false, integer: true),
                new StringParameter('action_name', 'Action class name (e.g. \\Kanboard\\Action\\TaskClose)', required: false),
                new StringParameter('event_name', 'Event identifier (e.g. task.move.column)', required: false),
                new StringParameter('params', 'JSON object of action parameters (e.g. {"column_id": 4, "color_id": "red"})', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'list_available' => $this->callApi('getAvailableActions'),
            'list_events' => $this->callApi('getAvailableActionEvents'),
            'get_compatible_events' => $this->getCompatibleEvents($args),
            'get_project_actions' => $this->getProjectActions($args),
            'create' => $this->create($args),
            'remove' => $this->remove($args),
            'bulk_create' => $this->bulkCreate($args),
            'bulk_remove' => $this->executeBulk($args, 'removeAction', 'action_id'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function getCompatibleEvents(array $args): ToolResult
    {
        $actionName = trim((string) ($args['action_name'] ?? ''));
        if ($actionName === '') {
            return ToolResult::error('action_name is required for get_compatible_events.');
        }

        return $this->callApi('getCompatibleActionEvents', ['action_name' => $actionName]);
    }

    private function getProjectActions(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for get_project_actions.');
        }

        return $this->callApi('getActions', ['project_id' => $projectId]);
    }

    private function create(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $eventName = trim((string) ($args['event_name'] ?? ''));
        $actionName = trim((string) ($args['action_name'] ?? ''));
        $paramsRaw = trim((string) ($args['params'] ?? ''));

        if ($projectId === null || $eventName === '' || $actionName === '' || $paramsRaw === '') {
            return ToolResult::error('project_id, event_name, action_name, and params (JSON object) are required for create.');
        }

        $actionParams = json_decode($paramsRaw, true);
        if (!is_array($actionParams)) {
            return ToolResult::error('params must be a valid JSON object.');
        }

        return $this->callApi('createAction', [
            'project_id' => $projectId,
            'event_name' => $eventName,
            'action_name' => $actionName,
            'params' => $actionParams,
        ]);
    }

    private function remove(array $args): ToolResult
    {
        $actionId = $this->requireInt($args, 'action_id');
        if ($actionId === null) {
            return ToolResult::error('action_id is required for remove.');
        }

        return $this->callApi('removeAction', ['action_id' => $actionId]);
    }

    private function bulkCreate(array $args): ToolResult
    {
        $operations = $this->parseOperations($args);
        if ($operations === null) {
            return ToolResult::error('operations (JSON array) is required for bulk_create. Each item needs project_id, event_name, action_name, and params.');
        }

        $requests = [];
        foreach ($operations as $i => $op) {
            if (!is_array($op) || !isset($op['project_id'], $op['event_name'], $op['action_name'], $op['params'])) {
                return ToolResult::error("Operation {$i}: missing required fields (project_id, event_name, action_name, params).");
            }
            $requests[] = ['method' => 'createAction', 'params' => $op];
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
}
