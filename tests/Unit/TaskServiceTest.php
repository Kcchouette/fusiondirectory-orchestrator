<?php

declare(strict_types=1);

namespace Orchestrator\Tests\Unit;

use Orchestrator\Task\TaskService;
use Orchestrator\Task\TaskGateway;
use PHPUnit\Framework\TestCase;

class TaskServiceTest extends TestCase
{
    public function testResolveEndpointReturnsNullForUnknownType(): void
    {
        $gateway = $this->createMock(TaskGateway::class);

        $service = new TaskService($gateway);

        $result = $service->handleTask('GET', 'nonExistentPlugin');

        $this->assertEmpty($result);
    }

    public function testListAllTasksReturnsArray(): void
    {
        $gateway = $this->createMock(TaskGateway::class);
        $gateway->method('getTask')
            ->with('tasks')
            ->willReturn([['cn' => ['mail']]]);

        $service = new TaskService($gateway);

        $result = $service->listAllTasks();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testRemoveSubTasksDelegatesToGateway(): void
    {
        $gateway = $this->createMock(TaskGateway::class);
        $gateway->method('removeCompletedTasks')
            ->willReturn(['updated' => ['dn1']]);

        $service = new TaskService($gateway);

        $result = $service->removeSubTasks();

        $this->assertEquals(['updated' => ['dn1']], $result);
    }

    public function testActivateCyclicTasksDelegatesToGateway(): void
    {
        $gateway = $this->createMock(TaskGateway::class);
        $gateway->method('activateCyclicTasks')
            ->willReturn(['no tasks']);

        $service = new TaskService($gateway);

        $result = $service->activateCyclicTasks();

        $this->assertEquals(['no tasks'], $result);
    }

    public function testRestartFailedTasksPassesTaskName(): void
    {
        $gateway = $this->createMock(TaskGateway::class);
        $gateway->expects($this->once())
            ->method('restartFailedSubtasks')
            ->with('myTask')
            ->willReturn(['updated' => []]);

        $service = new TaskService($gateway);

        $service->restartFailedTasks('myTask');
    }

    public function testHandleTaskGetReturnsGatewayResult(): void
    {
        $gateway = $this->createMock(TaskGateway::class);
        $gateway->method('getTask')
            ->with('tasks')
            ->willReturn(['list' => 'of tasks']);

        $service = new TaskService($gateway);

        $result = $service->handleTask('GET', null);

        $this->assertEquals(['list' => 'of tasks'], $result);
    }

    public function testHandleTaskGetAllTasks(): void
    {
        $gateway = $this->createMock(TaskGateway::class);
        $gateway->method('getTask')
            ->with('tasks')
            ->willReturn([['cn' => ['audit']]]);

        $service = new TaskService($gateway);

        $result = $service->handleTask('GET', null);

        $this->assertIsArray($result);
    }
}
