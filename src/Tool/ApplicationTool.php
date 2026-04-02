<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Kanboard\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\Kanboard\KanboardClient;

/**
 * Kanboard application info and settings tool.
 *
 * Provides read-only access to application version, timezone, and
 * the default task colors with their names and hex codes.
 */
final readonly class ApplicationTool
{
    private const array ACTIONS = [
        'get_version', 'get_timezone', 'get_default_task_colors', 'get_default_task_color',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_application',
            description: 'Get Kanboard application info: version, timezone, default task colors and their hex codes.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'get_version' => $this->callApi('getVersion'),
            'get_timezone' => $this->callApi('getTimezone'),
            'get_default_task_colors' => $this->callApi('getDefaultTaskColors'),
            'get_default_task_color' => $this->callApi('getDefaultTaskColor'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
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
