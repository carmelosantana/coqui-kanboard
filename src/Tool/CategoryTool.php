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
 * Kanboard category management tool.
 *
 * Covers category CRUD and bulk operations within projects.
 */
final readonly class CategoryTool
{
    private const array ACTIONS = [
        'list', 'get', 'create', 'update', 'remove',
        'bulk_create', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_category',
            description: 'Manage Kanboard categories: list, create, update, remove, and bulk operations.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new NumberParameter('category_id', 'Category ID', required: false, integer: true),
                new StringParameter('name', 'Category name', required: false),
                new StringParameter('color_id', 'Color identifier', required: false),
                new StringParameter('description', 'Category description', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'list' => $this->list($args),
            'get' => $this->get($args),
            'create' => $this->create($args),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'bulk_create' => $this->executeBulk($args, 'createCategory', 'name'),
            'bulk_remove' => $this->executeBulk($args, 'removeCategory', 'category_id'),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function list(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for list.');
        }

        return $this->callApi('getAllCategories', ['project_id' => $projectId]);
    }

    private function get(array $args): ToolResult
    {
        $categoryId = $this->requireInt($args, 'category_id');
        if ($categoryId === null) {
            return ToolResult::error('category_id is required for get.');
        }

        return $this->callApi('getCategory', ['category_id' => $categoryId]);
    }

    private function create(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        $name = trim((string) ($args['name'] ?? ''));

        if ($projectId === null || $name === '') {
            return ToolResult::error('project_id and name are required for create.');
        }

        $params = ['project_id' => $projectId, 'name' => $name];
        $this->addOptionalString($params, $args, 'color_id');
        $this->addOptionalString($params, $args, 'description');

        return $this->callApi('createCategory', $params);
    }

    private function update(array $args): ToolResult
    {
        $categoryId = $this->requireInt($args, 'category_id');
        if ($categoryId === null) {
            return ToolResult::error('category_id is required for update.');
        }

        $params = ['id' => $categoryId];
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'color_id');
        $this->addOptionalString($params, $args, 'description');

        return $this->callApi('updateCategory', $params);
    }

    private function remove(array $args): ToolResult
    {
        $categoryId = $this->requireInt($args, 'category_id');
        if ($categoryId === null) {
            return ToolResult::error('category_id is required for remove.');
        }

        return $this->callApi('removeCategory', ['category_id' => $categoryId]);
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

    private function addOptionalString(array &$params, array $args, string $key): void
    {
        $value = trim((string) ($args[$key] ?? ''));
        if ($value !== '') {
            $params[$key] = $value;
        }
    }
}
