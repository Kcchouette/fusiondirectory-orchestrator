<?php

declare(strict_types=1);

namespace Orchestrator\Task;

use Orchestrator\Ldap\Ldap;

class TaskService
{
    private TaskGateway $gateway;

    public function __construct(Ldap $ldap)
    {
        $this->gateway = new TaskGateway($ldap);
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
                if (class_exists($objectType)) {
                    $endpoint = new $objectType($this->gateway);
                    $result = $endpoint->processEndPointGet();
                }
                break;

            case 'POST':
                if (class_exists($objectType)) {
                    $endpoint = new $objectType($this->gateway);
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
                        if (class_exists($objectType)) {
                            $endpoint = new $objectType($this->gateway);
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
}
