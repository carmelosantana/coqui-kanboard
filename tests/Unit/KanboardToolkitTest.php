<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Kanboard\KanboardClient;
use CoquiBot\Toolkits\Kanboard\KanboardToolkit;
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
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

test('toolkit implements ToolkitInterface', function () {
    $client = new KanboardClient();
    $toolkit = new KanboardToolkit($client);

    expect($toolkit)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolkitInterface::class);
});

test('tools returns all ten kanboard tools', function () {
    $client = new KanboardClient();
    $toolkit = new KanboardToolkit($client);
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(10);

    $names = array_map(fn($tool) => $tool->name(), $tools);
    expect($names)->toBe([
        'kanboard_project',
        'kanboard_task',
        'kanboard_subtask',
        'kanboard_swimlane',
        'kanboard_board',
        'kanboard_column',
        'kanboard_category',
        'kanboard_comment',
        'kanboard_tag',
        'kanboard_task_file',
    ]);
});

test('each tool implements ToolInterface', function () {
    $client = new KanboardClient();
    $toolkit = new KanboardToolkit($client);
    $tools = $toolkit->tools();

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolInterface::class);
    }
});

test('guidelines returns non-empty string with XML tag', function () {
    $client = new KanboardClient();
    $toolkit = new KanboardToolkit($client);

    expect($toolkit->guidelines())
        ->toBeString()
        ->not->toBeEmpty()
        ->toContain('KANBOARD-TOOLKIT-GUIDELINES');
});

test('fromEnv creates instance', function () {
    $toolkit = KanboardToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(KanboardToolkit::class);
});

test('client reports unconfigured when no credentials set', function () {
    $client = new KanboardClient();

    expect($client->isConfigured())->toBeFalse();
});

test('client reports configured when credentials provided', function () {
    $client = new KanboardClient(
        url: 'http://localhost/jsonrpc.php',
        username: 'jsonrpc',
        token: 'test-token',
    );

    expect($client->isConfigured())->toBeTrue();
});

test('project tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new ProjectTool($client))->build();

    expect($tool->name())->toBe('kanboard_project');
});

test('task tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new TaskTool($client))->build();

    expect($tool->name())->toBe('kanboard_task');
});

test('subtask tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new SubtaskTool($client))->build();

    expect($tool->name())->toBe('kanboard_subtask');
});

test('swimlane tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new SwimlaneTool($client))->build();

    expect($tool->name())->toBe('kanboard_swimlane');
});

test('board tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new BoardTool($client))->build();

    expect($tool->name())->toBe('kanboard_board');
});

test('column tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new ColumnTool($client))->build();

    expect($tool->name())->toBe('kanboard_column');
});

test('category tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new CategoryTool($client))->build();

    expect($tool->name())->toBe('kanboard_category');
});

test('comment tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new CommentTool($client))->build();

    expect($tool->name())->toBe('kanboard_comment');
});

test('tag tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new TagTool($client))->build();

    expect($tool->name())->toBe('kanboard_tag');
});

test('task file tool builds with correct name', function () {
    $client = new KanboardClient();
    $tool = (new TaskFileTool($client))->build();

    expect($tool->name())->toBe('kanboard_task_file');
});

test('project tool returns error for unknown action', function () {
    $client = new KanboardClient();
    $tool = (new ProjectTool($client))->build();
    $result = $tool->execute(['action' => 'nonexistent']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('task tool requires task_id for get action', function () {
    $client = new KanboardClient();
    $tool = (new TaskTool($client))->build();
    $result = $tool->execute(['action' => 'get']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('task_id');
});

test('subtask tool requires task_id for list action', function () {
    $client = new KanboardClient();
    $tool = (new SubtaskTool($client))->build();
    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('task_id');
});

test('category tool requires project_id for list action', function () {
    $client = new KanboardClient();
    $tool = (new CategoryTool($client))->build();
    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id');
});

test('comment tool requires task_id for list action', function () {
    $client = new KanboardClient();
    $tool = (new CommentTool($client))->build();
    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('task_id');
});

test('board tool requires project_id', function () {
    $client = new KanboardClient();
    $tool = (new BoardTool($client))->build();
    $result = $tool->execute(['project_id' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id');
});

test('column tool requires project_id for create with missing title', function () {
    $client = new KanboardClient();
    $tool = (new ColumnTool($client))->build();
    $result = $tool->execute(['action' => 'create', 'project_id' => 1]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('title');
});

test('tag tool requires project_id for list_by_project', function () {
    $client = new KanboardClient();
    $tool = (new TagTool($client))->build();
    $result = $tool->execute(['action' => 'list_by_project']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('project_id');
});

test('task file tool requires file_id for get', function () {
    $client = new KanboardClient();
    $tool = (new TaskFileTool($client))->build();
    $result = $tool->execute(['action' => 'get']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('file_id');
});

test('bulk operations require valid JSON operations', function () {
    $client = new KanboardClient();
    $tool = (new ProjectTool($client))->build();
    $result = $tool->execute(['action' => 'bulk_create', 'operations' => 'not json']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('operations');
});

test('bulk operations require operations parameter', function () {
    $client = new KanboardClient();
    $tool = (new TaskTool($client))->build();
    $result = $tool->execute(['action' => 'bulk_create']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('operations');
});

test('swimlane tool requires swimlane_id for get action', function () {
    $client = new KanboardClient();
    $tool = (new SwimlaneTool($client))->build();
    $result = $tool->execute(['action' => 'get']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('swimlane_id');
});
