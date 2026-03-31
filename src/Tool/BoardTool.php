<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Kanboard\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CoquiBot\Toolkits\Kanboard\KanboardClient;

/**
 * Kanboard board retrieval tool.
 *
 * Returns the full board state: swimlanes → columns → tasks.
 * This is a read-only tool with a single action.
 */
final readonly class BoardTool
{
    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_board',
            description: 'Get the full Kanboard board state for a project. Returns all swimlanes with their columns and tasks.',
            parameters: [
                new NumberParameter('project_id', 'Project ID', required: true, integer: true),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $projectId = (int) ($args['project_id'] ?? 0);
        if ($projectId <= 0) {
            return ToolResult::error('project_id is required.');
        }

        try {
            $result = $this->client->call('getBoard', ['project_id' => $projectId]);

            return ToolResult::success(
                json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null',
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
