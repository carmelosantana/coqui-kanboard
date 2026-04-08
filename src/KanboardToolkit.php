<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitKanboard;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitKanboard\Tool\ActionTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\AdminTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\CategoryTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\ColumnTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\CommentTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\FileTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\MeTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\MetadataTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\ProjectTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\SubtaskTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\SwimlaneTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\TagTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\TaskLinkTool;
use CarmeloSantana\CoquiToolkitKanboard\Tool\TaskTool;

/**
 * Kanboard project management toolkit for Coqui.
 *
 * Provides 14 domain-level tools covering the full Kanboard JSON-RPC 2.0 API:
 * projects (with board state and app info), tasks, subtasks (with time tracking),
 * swimlanes, columns, categories, comments, tags, files (project + task), admin
 * operations (users, groups, permissions), personal dashboard (me), automatic
 * actions, links (internal + external), and metadata.
 *
 * Supports dual-auth: primary credentials for normal operations, optional
 * KANBOARD_ADMIN_TOKEN (Application API) for admin operations that bypass
 * project-level permission checks.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * Credentials (KANBOARD_URL, KANBOARD_USERNAME, KANBOARD_API_TOKEN) are managed
 * through Coqui's credential system. KANBOARD_ADMIN_TOKEN is optional.
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
            (new ColumnTool($this->client))->build(),
            (new CategoryTool($this->client))->build(),
            (new CommentTool($this->client))->build(),
            (new TagTool($this->client))->build(),
            (new FileTool($this->client))->build(),
            // Administration & personal
            (new AdminTool($this->client))->build(),
            (new MeTool($this->client))->build(),
            // Automation & linking
            (new ActionTool($this->client))->build(),
            (new TaskLinkTool($this->client))->build(),
            // Metadata
            (new MetadataTool($this->client))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <KANBOARD-TOOLKIT-GUIDELINES>
            ## Kanboard Project Management

            You have full access to a Kanboard instance through 14 tools covering all project
            management domains. Use these tools to manage projects, tasks, users, and workflows.

            ### Dual-Auth Architecture
            - **Primary credentials** (KANBOARD_URL, KANBOARD_USERNAME, KANBOARD_API_TOKEN) are used
              for all normal operations via `kanboard_project`, `kanboard_task`, etc.
            - **Admin token** (KANBOARD_ADMIN_TOKEN, optional) is the Application API token from
              Kanboard Settings → API. When set, `kanboard_admin` routes through it automatically,
              bypassing project-level permission checks. This solves the common issue where even
              app-admin users can't manage project permissions via the User API.
            - **Personal "Me" tools** (KANBOARD_ME) require User API auth (not `jsonrpc` username).
              They show the authenticated user's dashboard, activity, and overdue tasks.

            ### Tool Overview — Core (10 tools)
            - `kanboard_project` — Projects, board state, activity, app info (version/colors/roles)
            - `kanboard_task` — Full task lifecycle: create, search, move, close, overdue tracking
            - `kanboard_subtask` — Subtask CRUD, time tracking (start/stop timer, get time spent)
            - `kanboard_swimlane` — Swimlane management: create, enable/disable, reorder
            - `kanboard_column` — Column CRUD and reordering within projects
            - `kanboard_category` — Category management for task classification
            - `kanboard_comment` — Task comments with Markdown (auto-resolves user_id)
            - `kanboard_tag` — Project and task-level tag management
            - `kanboard_file` — Project and task file attachments (upload/download as base64)
            - `kanboard_metadata` — Arbitrary key-value storage on projects and tasks

            ### Tool Overview — Administration & Personal (2 tools)
            - `kanboard_admin` — Users, groups, project permissions (all via admin token when available)
            - `kanboard_me` — Personal dashboard, activity stream, overdue tasks, private projects

            ### Tool Overview — Automation & Links (2 tools)
            - `kanboard_action` — Automatic actions: list available, create/remove project automations
            - `kanboard_link` — Link types, internal task-to-task links, external URL links

            ### Workflow Best Practices
            1. **Discovery first**: Use `kanboard_project` action `list` to see available projects
            2. **Board overview**: Use `kanboard_project` action `get_board` to see full board state
            3. **Bulk operations**: Use `bulk_*` actions for multiple operations — they use JSON-RPC
               batch requests for efficiency (max 50 per call)
            4. **Task search**: Use `kanboard_task` action `search` with Kanboard's query syntax
               (e.g. "status:open assignee:me due:tomorrow")
            5. **User setup**: Use `kanboard_admin` for user/group CRUD and project permission management
            6. **Personal view**: Use `kanboard_me` for the authenticated user's dashboard and overdue tasks
            7. **Automation**: Use `kanboard_action` to set up automatic workflows. List available
               actions first, then compatible events.
            8. **Time tracking**: Use `kanboard_subtask` start_timer/stop_timer to track time

            ### Important Notes
            - All IDs are integers. Create operations return the new ID.
            - Dates use YYYY-MM-DD format. Timestamps use ISO 8601.
            - Task colors: yellow, blue, green, purple, red, orange, grey, brown, deep_orange,
              dark_grey, teal, lime, light_green, amber.
            - File tools handle binary data as base64 strings.
            - Project roles: project-manager, project-member, project-viewer.
            - Application roles: app-admin, app-manager, app-user.
            </KANBOARD-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
