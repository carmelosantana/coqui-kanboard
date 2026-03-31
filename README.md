# Kanboard Toolkit for Coqui

A comprehensive Kanboard project management toolkit for the [Coqui](https://github.com/coquibot/coqui) AI agent. Provides 10 domain-level tools covering the full Kanboard JSON-RPC 2.0 API with bulk operation support.

## Installation

```bash
composer require coquibot/coqui-toolkit-kanboard
```

The toolkit is auto-discovered by Coqui's `ToolkitDiscovery` — no manual registration needed.

## Credentials

The toolkit requires three environment variables, managed through Coqui's credential system:

| Variable | Description |
|----------|-------------|
| `KANBOARD_URL` | Kanboard JSON-RPC endpoint (e.g. `https://kanboard.example.com/jsonrpc.php`) |
| `KANBOARD_USERNAME` | Username for authentication |
| `KANBOARD_API_TOKEN` | API token or password |

### Authentication Modes

Kanboard supports three authentication methods, all through HTTP Basic Auth:

| Mode | Username | Token |
|------|----------|-------|
| **Application API** | `jsonrpc` | Application API token (from Settings → API) |
| **User API (password)** | User login | User password |
| **User API (personal token)** | User login | Personal API token (from User Profile → API) |

Set credentials interactively through the Coqui bot:

```
> Set my Kanboard URL to https://kanboard.example.com/jsonrpc.php
```

Or configure them in your workspace `.env`:

```env
KANBOARD_URL=https://kanboard.example.com/jsonrpc.php
KANBOARD_USERNAME=jsonrpc
KANBOARD_API_TOKEN=your-api-token-here
```

## Tools

### `kanboard_project`

Manage projects: create, list, update, enable/disable, public access control, and activity streams.

**Actions:** `create`, `get`, `get_by_name`, `get_by_identifier`, `get_by_email`, `list`, `update`, `remove`, `enable`, `disable`, `enable_public_access`, `disable_public_access`, `get_activity`, `get_activities`, `bulk_create`, `bulk_update`, `bulk_remove`

### `kanboard_task`

Full task lifecycle management: create, search, move between projects, close/reopen, and overdue tracking.

**Actions:** `create`, `get`, `get_by_reference`, `list`, `search`, `get_overdue`, `get_overdue_by_project`, `update`, `open`, `close`, `remove`, `move_position`, `move_to_project`, `duplicate_to_project`, `bulk_create`, `bulk_update`, `bulk_move`, `bulk_close`, `bulk_remove`

### `kanboard_subtask`

Subtask CRUD under tasks. Status values: 0=Todo, 1=In Progress, 2=Done.

**Actions:** `create`, `get`, `list`, `update`, `remove`, `bulk_create`, `bulk_update`, `bulk_remove`

### `kanboard_swimlane`

Swimlane management: create, enable/disable, reorder.

**Actions:** `list_active`, `list_all`, `get`, `get_by_name`, `create`, `update`, `remove`, `enable`, `disable`, `change_position`, `bulk_create`, `bulk_remove`

### `kanboard_board`

Get the full board state for a project: swimlanes → columns → tasks.

**Parameters:** `project_id` (required)

### `kanboard_column`

Column CRUD and reordering within projects.

**Actions:** `list`, `get`, `create`, `update`, `remove`, `change_position`, `bulk_create`, `bulk_remove`

### `kanboard_category`

Category management for task classification.

**Actions:** `list`, `get`, `create`, `update`, `remove`, `bulk_create`, `bulk_remove`

### `kanboard_comment`

Task comments with Markdown support.

**Actions:** `create`, `get`, `list`, `update`, `remove`, `bulk_create`, `bulk_remove`

### `kanboard_tag`

Project-level and task-level tag management.

**Actions:** `list_all`, `list_by_project`, `create`, `update`, `remove`, `get_task_tags`, `set_task_tags`, `bulk_set_task_tags`

### `kanboard_task_file`

File attachments on tasks. Upload and download use base64 encoding.

**Actions:** `create`, `list`, `get`, `download`, `remove`, `remove_all`

## Bulk Operations

Most tools support bulk actions via JSON-RPC batch requests. Pass a JSON array to the `operations` parameter:

```json
[
    {"project_id": 1, "title": "Task A", "color_id": "green"},
    {"project_id": 1, "title": "Task B", "color_id": "blue"},
    {"project_id": 1, "title": "Task C", "color_id": "red"}
]
```

## Bundled Skill

The package includes a `kanboard-project-manager` skill that provides project management guidelines to the agent. It's automatically discovered when the package is installed.

## Project Manager Role

A `project-manager` role is included in Coqui core at `config/roles/project-manager.md`. This role specializes the agent for project management tasks using Kanboard.

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Static analysis
composer analyse
```

## Requirements

- PHP 8.4+
- Kanboard instance with API access
- `carmelosantana/php-agents` ^0.4

## License

MIT
