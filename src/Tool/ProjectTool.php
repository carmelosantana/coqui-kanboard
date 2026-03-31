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
 * Kanboard project management tool.
 *
 * Covers all project CRUD operations plus enable/disable, public access,
 * activity streams, and bulk operations.
 */
final readonly class ProjectTool
{
    private const array ACTIONS = [
        'create', 'get', 'get_by_name', 'get_by_identifier', 'get_by_email',
        'list', 'update', 'remove', 'enable', 'disable',
        'enable_public_access', 'disable_public_access',
        'get_activity', 'get_activities',
        'bulk_create', 'bulk_update', 'bulk_remove',
    ];

    public function __construct(
        private KanboardClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'kanboard_project',
            description: 'Manage Kanboard projects: create, read, update, delete, enable/disable, public access, activity streams, and bulk operations.',
            parameters: [
                new EnumParameter('action', 'The operation to perform', self::ACTIONS),
                new NumberParameter('project_id', 'Project ID', required: false, integer: true),
                new StringParameter('name', 'Project name', required: false),
                new StringParameter('identifier', 'Short unique project identifier', required: false),
                new StringParameter('description', 'Project description', required: false),
                new NumberParameter('owner_id', 'Owner user ID', required: false, integer: true),
                new StringParameter('start_date', 'Project start date (YYYY-MM-DD)', required: false),
                new StringParameter('end_date', 'Project end date (YYYY-MM-DD)', required: false),
                new StringParameter('email', 'Project email address', required: false),
                new StringParameter('project_ids', 'JSON array of project IDs for get_activities (e.g. [1,2,3])', required: false),
                new StringParameter('operations', 'JSON array of operations for bulk actions', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'create' => $this->create($args),
            'get' => $this->get($args),
            'get_by_name' => $this->getByName($args),
            'get_by_identifier' => $this->getByIdentifier($args),
            'get_by_email' => $this->getByEmail($args),
            'list' => $this->list(),
            'update' => $this->update($args),
            'remove' => $this->remove($args),
            'enable' => $this->toggleProject($args, 'enableProject'),
            'disable' => $this->toggleProject($args, 'disableProject'),
            'enable_public_access' => $this->toggleProject($args, 'enableProjectPublicAccess'),
            'disable_public_access' => $this->toggleProject($args, 'disableProjectPublicAccess'),
            'get_activity' => $this->getActivity($args),
            'get_activities' => $this->getActivities($args),
            'bulk_create' => $this->bulkCreate($args),
            'bulk_update' => $this->bulkUpdate($args),
            'bulk_remove' => $this->bulkRemove($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function create(array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('Project name is required for create.');
        }

        $params = ['name' => $name];
        $this->addOptionalString($params, $args, 'description');
        $this->addOptionalInt($params, $args, 'owner_id');
        $this->addOptionalString($params, $args, 'identifier');
        $this->addOptionalString($params, $args, 'start_date');
        $this->addOptionalString($params, $args, 'end_date');
        $this->addOptionalString($params, $args, 'email');

        return $this->callApi('createProject', $params);
    }

    private function get(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for get.');
        }

        return $this->callApi('getProjectById', ['project_id' => $projectId]);
    }

    private function getByName(array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name is required for get_by_name.');
        }

        return $this->callApi('getProjectByName', ['name' => $name]);
    }

    private function getByIdentifier(array $args): ToolResult
    {
        $identifier = trim((string) ($args['identifier'] ?? ''));
        if ($identifier === '') {
            return ToolResult::error('identifier is required for get_by_identifier.');
        }

        return $this->callApi('getProjectByIdentifier', ['identifier' => $identifier]);
    }

    private function getByEmail(array $args): ToolResult
    {
        $email = trim((string) ($args['email'] ?? ''));
        if ($email === '') {
            return ToolResult::error('email is required for get_by_email.');
        }

        return $this->callApi('getProjectByEmail', ['email' => $email]);
    }

    private function list(): ToolResult
    {
        return $this->callApi('getAllProjects');
    }

    private function update(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for update.');
        }

        $params = ['project_id' => $projectId];
        $this->addOptionalString($params, $args, 'name');
        $this->addOptionalString($params, $args, 'description');
        $this->addOptionalInt($params, $args, 'owner_id');
        $this->addOptionalString($params, $args, 'identifier');
        $this->addOptionalString($params, $args, 'start_date');
        $this->addOptionalString($params, $args, 'end_date');
        $this->addOptionalString($params, $args, 'email');

        return $this->callApi('updateProject', $params);
    }

    private function remove(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for remove.');
        }

        return $this->callApi('removeProject', ['project_id' => $projectId]);
    }

    private function toggleProject(array $args, string $method): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required.');
        }

        return $this->callApi($method, ['project_id' => $projectId]);
    }

    private function getActivity(array $args): ToolResult
    {
        $projectId = $this->requireInt($args, 'project_id');
        if ($projectId === null) {
            return ToolResult::error('project_id is required for get_activity.');
        }

        return $this->callApi('getProjectActivity', ['project_id' => $projectId]);
    }

    private function getActivities(array $args): ToolResult
    {
        $raw = trim((string) ($args['project_ids'] ?? ''));
        if ($raw === '') {
            return ToolResult::error('project_ids (JSON array) is required for get_activities.');
        }

        $ids = json_decode($raw, true);
        if (!is_array($ids)) {
            return ToolResult::error('project_ids must be a valid JSON array of integers.');
        }

        return $this->callApi('getProjectActivities', ['project_ids' => array_map(intval(...), $ids)]);
    }

    private function bulkCreate(array $args): ToolResult
    {
        return $this->executeBulk($args, 'createProject', 'name');
    }

    private function bulkUpdate(array $args): ToolResult
    {
        return $this->executeBulk($args, 'updateProject', 'project_id');
    }

    private function bulkRemove(array $args): ToolResult
    {
        return $this->executeBulk($args, 'removeProject', 'project_id');
    }

    /**
     * @param array<string, mixed> $args
     */
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

    /**
     * @param array<string, mixed> $params
     */
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

    /**
     * @param array<int, array{method: string, params: array<string, mixed>}> $requests
     */
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

    /**
     * @return array<int, mixed>|null
     */
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

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $args
     */
    private function addOptionalString(array &$params, array $args, string $key): void
    {
        $value = trim((string) ($args[$key] ?? ''));
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $args
     */
    private function addOptionalInt(array &$params, array $args, string $key): void
    {
        if (isset($args[$key]) && $args[$key] !== '') {
            $params[$key] = (int) $args[$key];
        }
    }
}
