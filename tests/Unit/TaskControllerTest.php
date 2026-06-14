<?php

declare(strict_types=1);

namespace Orchestrator\Tests\Unit;

use Orchestrator\Http\Controller\TaskController;
use Orchestrator\Task\TaskService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class TaskControllerTest extends TestCase
{
    private TaskController $controller;
    private TaskService $taskService;

    protected function setUp(): void
    {
        $this->taskService = $this->createMock(TaskService::class);
        $this->controller = new TaskController($this->taskService);
    }

    private function jsonResponse(Response $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testListTasksReturns200(): void
    {
        $this->taskService
            ->method('listAllTasks')
            ->willReturn(['tasks' => [['cn' => ['mail']]]]);

        $request = new Request('GET', '/api/tasks');
        $response = new Response();

        $result = $this->controller->listTasks($request, $response);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertArrayHasKey('tasks', $this->jsonResponse($result));
    }

    public function testHandleTaskReturns200ForGet(): void
    {
        $this->taskService
            ->method('handleTask')
            ->with('GET', 'mail', null)
            ->willReturn(['dn' => 'cn=test']);

        $request = new Request('GET', '/api/tasks/mail');
        $response = new Response();
        $args = ['objectType' => 'mail'];

        $result = $this->controller->handleTask($request, $response, $args);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals(['dn' => 'cn=test'], $this->jsonResponse($result));
    }

    public function testHandleTaskReturns400OnException(): void
    {
        $this->taskService
            ->method('handleTask')
            ->willThrowException(new \RuntimeException('Task not found', 404));

        $request = new Request('GET', '/api/tasks/unknown');
        $response = new Response();
        $args = ['objectType' => 'unknown'];

        $result = $this->controller->handleTask($request, $response, $args);

        $this->assertEquals(404, $result->getStatusCode());
        $this->assertEquals('Task not found', $this->jsonResponse($result)['message']);
    }

    public function testRemoveSubTasksReturns200(): void
    {
        $this->taskService
            ->method('removeSubTasks')
            ->willReturn(['updated' => ['cn=sub1']]);

        $request = new Request('PATCH', '/api/tasks/removeSubTasks');
        $response = new Response();

        $result = $this->controller->removeSubTasks($request, $response);

        $this->assertEquals(200, $result->getStatusCode());
        $this->assertArrayHasKey('updated', $this->jsonResponse($result));
    }

    public function testActivateCyclicTasksReturns200(): void
    {
        $this->taskService
            ->method('activateCyclicTasks')
            ->willReturn(['dn=test' => ['result' => 'ok']]);

        $request = new Request('PATCH', '/api/tasks/activateCyclicTasks');
        $response = new Response();

        $result = $this->controller->activateCyclicTasks($request, $response);

        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testRestartFailedTasksReturns200(): void
    {
        $this->taskService
            ->method('restartFailedTasks')
            ->willReturn(['updated' => []]);

        $request = (new Request('PATCH', '/api/tasks/restartFailedTasks'))
            ->withParsedBody([]);
        $response = new Response();

        $result = $this->controller->restartFailedTasks($request, $response);

        $this->assertEquals(200, $result->getStatusCode());
    }
}
