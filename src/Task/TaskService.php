<?php

declare(strict_types=1);

namespace Orchestrator\Task;

use Orchestrator\Ldap\Ldap;
use Orchestrator\Task\Plugin\EndpointInterface;

class TaskService
{
    private const PLUGIN_MAP = [
        'mail' => Plugin\Mail::class,
        'archive' => Plugin\Archive::class,
        'audit' => Plugin\Audit::class,
        'automaticGroups' => Plugin\AutomaticGroups::class,
        'automaticGroupsDynamic' => Plugin\AutomaticGroups::class,
        'extract' => Plugin\Extractor::class,
        'lifeCycle' => Plugin\LifeCycle::class,
        'notifications' => Plugin\Notifications::class,
        'reminder' => Plugin\Reminder::class,
    ];

    public function __construct(
        private readonly TaskGateway $gateway,
    ) {
    }

    public function getGateway(): TaskGateway
    {
        return $this->gateway;
    }

    public function listAllTasks(): array
    {
        return $this->gateway->getTask('tasks');
    }

    public function handleTask(string $method, ?string $objectType, ?array $jsonBody = null): array
    {
        if ($objectType === null) {
            if ($method === 'GET') {
                return $this->gateway->getTask('tasks');
            }
            throw new \RuntimeException("Method $method not allowed", 405);
        }

        $result = null;

        switch ($method) {
            case 'GET':
                $endpoint = $this->resolveEndpoint($objectType);
                if ($endpoint !== null) {
                    $result = $endpoint->processEndPointGet();
                }
                break;

            case 'POST':
                $endpoint = $this->resolveEndpoint($objectType);
                if ($endpoint !== null) {
                    $result = $endpoint->processEndPointPost($jsonBody);
                }
                break;

            case 'PATCH':
                switch ($objectType) {
                    case 'removeSubTasks':
                        $result = $this->gateway->removeCompletedTasks();
                        break;
                    case 'activateCyclicTasks':
                        $result = $this->gateway->activateCyclicTasks();
                        break;
                    case 'restartFailedTasks':
                        $taskName = $jsonBody['taskName'] ?? null;
                        $result = $this->gateway->restartFailedSubtasks($taskName);
                        break;
                    default:
                        $endpoint = $this->resolveEndpoint($objectType);
                        if ($endpoint !== null) {
                            $result = $endpoint->processEndPointPatch($jsonBody);
                        }
                        break;
                }
                break;

            case 'DELETE':
                break;

            default:
                throw new \RuntimeException("Method $method not allowed", 405);
        }

        return $result ?? [];
    }

    public function removeSubTasks(): array
    {
        return $this->gateway->removeCompletedTasks();
    }

    public function activateCyclicTasks(): array
    {
        return $this->gateway->activateCyclicTasks();
    }

    public function restartFailedTasks(?string $taskName = null): array
    {
        return $this->gateway->restartFailedSubtasks($taskName);
    }

    private function resolveEndpoint(string $objectType): ?EndpointInterface
    {
        $className = self::PLUGIN_MAP[strtolower($objectType)] ?? null;

        if ($className === null || !class_exists($className)) {
            return null;
        }

        return new $className($this->gateway);
    }
}
