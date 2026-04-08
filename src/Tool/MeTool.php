<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitKanboard\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\CoquiToolkitKanboard\KanboardClient;

/**
 * Kanboard "Me" procedures tool.
 *
 * Provides access to the authenticated user's personal data: dashboard,
 * activity stream, projects, overdue tasks, and profile info.
 *
 * These procedures ONLY work with User API credentials (username + password/token).
 * Application API (username=jsonrpc) has no user context and will return errors.
 */
final readonly class MeTool
{
    private const array ACTIONS = [
        'get_me', 'dashboard', 'activity', 'projects', 'projects_list',
        'overdue', 'create_private_project',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_me',
            description: 'Access the authenticated Kanboard user\'s personal data: profile (get_me), dashboard, activity stream, projects, overdue tasks, and create private projects. Requires User API credentials — does not work with Application API (jsonrpc).',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new StringParameter('name', 'Project name (for create_private_project)', required: false),
                new StringParameter('description', 'Project description (for create_private_project)', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        if ($this->client->isAppApi()) {
            return ToolResult::error(
                'kanboard_me requires User API credentials (personal username + password/token). '
                . 'The current KANBOARD_USERNAME is "jsonrpc" (Application API), which has no user context. '
                . 'Set KANBOARD_USERNAME to a real user login to use "Me" procedures.',
            );
        }

        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'get_me' => $this->callApi('getMe'),
            'dashboard' => $this->callApi('getMyDashboard'),
            'activity' => $this->callApi('getMyActivityStream'),
            'projects' => $this->callApi('getMyProjects'),
            'projects_list' => $this->callApi('getMyProjectsList'),
            'overdue' => $this->callApi('getMyOverdueTasks'),
            'create_private_project' => $this->createPrivateProject($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function createPrivateProject(array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name is required for create_private_project.');
        }

        $params = ['name' => $name];

        $description = trim((string) ($args['description'] ?? ''));
        if ($description !== '') {
            $params['description'] = $description;
        }

        return $this->callApi('createMyPrivateProject', $params);
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
}
