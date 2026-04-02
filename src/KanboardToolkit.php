<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Kanboard;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\Kanboard\Tool\ActionTool;
use CoquiBot\Toolkits\Kanboard\Tool\ApplicationTool;
use CoquiBot\Toolkits\Kanboard\Tool\BoardTool;
use CoquiBot\Toolkits\Kanboard\Tool\CategoryTool;
use CoquiBot\Toolkits\Kanboard\Tool\ColumnTool;
use CoquiBot\Toolkits\Kanboard\Tool\CommentTool;
use CoquiBot\Toolkits\Kanboard\Tool\ExternalTaskLinkTool;
use CoquiBot\Toolkits\Kanboard\Tool\GroupTool;
use CoquiBot\Toolkits\Kanboard\Tool\MetadataTool;
use CoquiBot\Toolkits\Kanboard\Tool\ProjectFileTool;
use CoquiBot\Toolkits\Kanboard\Tool\ProjectPermissionTool;
use CoquiBot\Toolkits\Kanboard\Tool\ProjectTool;
use CoquiBot\Toolkits\Kanboard\Tool\SubtaskTool;
use CoquiBot\Toolkits\Kanboard\Tool\SwimlaneTool;
use CoquiBot\Toolkits\Kanboard\Tool\TagTool;
use CoquiBot\Toolkits\Kanboard\Tool\TaskFileTool;
use CoquiBot\Toolkits\Kanboard\Tool\TaskLinkTool;
use CoquiBot\Toolkits\Kanboard\Tool\TaskTool;
use CoquiBot\Toolkits\Kanboard\Tool\UserTool;

/**
 * Kanboard project management toolkit for Coqui.
 *
 * Provides 19 domain-level tools covering the full Kanboard JSON-RPC 2.0 API:
 * projects, tasks, subtasks (with time tracking), swimlanes, board state, columns,
 * categories, comments, tags, task file attachments, users, groups, project
 * permissions, automatic actions, task links, external task links, project files,
 * metadata, and application info.
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
            // Core project management
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
            // Administration & permissions
            (new UserTool($this->client))->build(),
            (new GroupTool($this->client))->build(),
            (new ProjectPermissionTool($this->client))->build(),
            // Automation & linking
            (new ActionTool($this->client))->build(),
            (new TaskLinkTool($this->client))->build(),
            (new ExternalTaskLinkTool($this->client))->build(),
            // Files, metadata & app info
            (new ProjectFileTool($this->client))->build(),
            (new MetadataTool($this->client))->build(),
            (new ApplicationTool($this->client))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <KANBOARD-TOOLKIT-GUIDELINES>
            ## Kanboard Project Management

            You have full access to a Kanboard instance through 19 tools covering all project
            management domains. Use these tools to manage projects, tasks, users, and workflows.

            ### Tool Overview — Core
            - `kanboard_project` — Create, list, update, enable/disable projects, activity streams
            - `kanboard_task` — Full task lifecycle: create, search, move, close, overdue tracking
            - `kanboard_subtask` — Subtask CRUD, time tracking (start/stop timer, get time spent)
            - `kanboard_swimlane` — Swimlane management: create, enable/disable, reorder
            - `kanboard_board` — Get full board state (swimlanes → columns → tasks)
            - `kanboard_column` — Column CRUD and reordering within projects
            - `kanboard_category` — Category management for task classification
            - `kanboard_comment` — Task comments with Markdown (auto-resolves user_id)
            - `kanboard_tag` — Project and task-level tag management
            - `kanboard_task_file` — Task file attachments (upload/download as base64)

            ### Tool Overview — Administration
            - `kanboard_user` — User CRUD, enable/disable, roles (app-admin/manager/user), get_me
            - `kanboard_group` — Group CRUD, member add/remove/list, membership checks
            - `kanboard_project_permission` — User/group project access, role assignment (project-manager/member/viewer)

            ### Tool Overview — Automation & Links
            - `kanboard_action` — Automatic actions: list available, create/remove project automations
            - `kanboard_task_link` — Link types (blocks, relates to) and internal task-to-task links
            - `kanboard_external_task_link` — Link tasks to external URLs with dependency tracking

            ### Tool Overview — Files, Metadata & Info
            - `kanboard_project_file` — Project-level file attachments (upload/download as base64)
            - `kanboard_metadata` — Arbitrary key-value storage on projects and tasks
            - `kanboard_application` — App version, timezone, default task colors

            ### Workflow Best Practices
            1. **Discovery first**: Use `kanboard_project` action `list` to see available projects
            2. **Board overview**: Use `kanboard_board` to see the full state before making changes
            3. **Bulk operations**: For multiple creates/updates/deletes, use `bulk_*` actions —
               they use JSON-RPC batch requests for efficiency
            4. **Task search**: Use `kanboard_task` action `search` with Kanboard's query syntax
               (e.g. "status:open assignee:me due:tomorrow")
            5. **Overdue monitoring**: Use `get_overdue` to find tasks past their due date
            6. **User setup**: Use `kanboard_user` to manage users, then `kanboard_project_permission`
               to grant project access with appropriate roles
            7. **Automation**: Use `kanboard_action` to set up automatic workflows (auto-close,
               auto-assign, email on events). List available actions first, then compatible events.
            8. **Time tracking**: Use `kanboard_subtask` start_timer/stop_timer to track time on subtasks

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
            - File tools handle binary data as base64 strings.
            - Comments support Markdown formatting and auto-resolve user_id from authenticated user.
            - Project roles: project-manager, project-member, project-viewer.
            - Application roles: app-admin, app-manager, app-user.
            - Action classes use FQCN format: `\Kanboard\Action\TaskClose`, `\Kanboard\Action\TaskAssignColorColumn`, etc.
            </KANBOARD-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
