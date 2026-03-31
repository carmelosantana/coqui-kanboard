<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Kanboard;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\Kanboard\Tool\BoardTool;
use CoquiBot\Toolkits\Kanboard\Tool\CategoryTool;
use CoquiBot\Toolkits\Kanboard\Tool\ColumnTool;
use CoquiBot\Toolkits\Kanboard\Tool\CommentTool;
use CoquiBot\Toolkits\Kanboard\Tool\ProjectTool;
use CoquiBot\Toolkits\Kanboard\Tool\SubtaskTool;
use CoquiBot\Toolkits\Kanboard\Tool\SwimlaneTool;
use CoquiBot\Toolkits\Kanboard\Tool\TagTool;
use CoquiBot\Toolkits\Kanboard\Tool\TaskFileTool;
use CoquiBot\Toolkits\Kanboard\Tool\TaskTool;

/**
 * Kanboard project management toolkit for Coqui.
 *
 * Provides 10 domain-level tools covering the full Kanboard JSON-RPC 2.0 API:
 * projects, tasks, subtasks, swimlanes, board state, columns, categories,
 * comments, tags, and task file attachments.
 *
 * Each tool exposes an `action` enum parameter that dispatches to specific
 * Kanboard API methods, with bulk variants that use JSON-RPC batch requests.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * Credentials (KANBOARD_URL, KANBOARD_USERNAME, KANBOARD_API_TOKEN) are managed
 * through Coqui's credential system.
 */
final class KanboardToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly KanboardClient $client,
    ) {}

    /**
     * Factory method for ToolkitDiscovery — reads credentials from environment.
     */
    public static function fromEnv(): self
    {
        return new self(client: KanboardClient::fromEnv());
    }

    public function tools(): array
    {
        return [
            (new ProjectTool($this->client))->build(),
            (new TaskTool($this->client))->build(),
            (new SubtaskTool($this->client))->build(),
            (new SwimlaneTool($this->client))->build(),
            (new BoardTool($this->client))->build(),
            (new ColumnTool($this->client))->build(),
            (new CategoryTool($this->client))->build(),
            (new CommentTool($this->client))->build(),
            (new TagTool($this->client))->build(),
            (new TaskFileTool($this->client))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <KANBOARD-TOOLKIT-GUIDELINES>
            ## Kanboard Project Management

            You have full access to a Kanboard instance through 10 tools covering all project
            management domains. Use these tools to manage projects, tasks, and workflows.

            ### Tool Overview
            - `kanboard_project` — Create, list, update, enable/disable projects, activity streams
            - `kanboard_task` — Full task lifecycle: create, search, move, close, overdue tracking
            - `kanboard_subtask` — Subtask CRUD under tasks (status: 0=Todo, 1=In Progress, 2=Done)
            - `kanboard_swimlane` — Swimlane management: create, enable/disable, reorder
            - `kanboard_board` — Get full board state (swimlanes → columns → tasks)
            - `kanboard_column` — Column CRUD and reordering within projects
            - `kanboard_category` — Category management for task classification
            - `kanboard_comment` — Task comments with Markdown support
            - `kanboard_tag` — Project and task-level tag management
            - `kanboard_task_file` — File attachments (upload/download as base64)

            ### Workflow Best Practices
            1. **Discovery first**: Use `kanboard_project` action `list` to see available projects
            2. **Board overview**: Use `kanboard_board` to see the full state before making changes
            3. **Bulk operations**: For multiple creates/updates/deletes, use `bulk_*` actions —
               they use JSON-RPC batch requests for efficiency
            4. **Task search**: Use `kanboard_task` action `search` with Kanboard's query syntax
               (e.g. "status:open assignee:me due:tomorrow")
            5. **Overdue monitoring**: Use `get_overdue` to find tasks past their due date

            ### Authentication
            Three auth modes supported via KANBOARD_URL, KANBOARD_USERNAME, KANBOARD_API_TOKEN:
            - **Application API**: username=`jsonrpc`, token=application API token
            - **User API (password)**: username=user login, token=user password
            - **User API (personal token)**: username=user login, token=personal API token

            ### Important Notes
            - All IDs are integers. Project, task, column, swimlane, category, tag, and file IDs
              are returned by create operations.
            - Dates use YYYY-MM-DD format. Timestamps use ISO 8601.
            - Task colors: yellow, blue, green, purple, red, orange, grey, brown, deep_orange,
              dark_grey, teal, lime, light_green, amber.
            - The `kanboard_task_file` tool handles binary data as base64 strings.
            - Comments support Markdown formatting.
            </KANBOARD-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
