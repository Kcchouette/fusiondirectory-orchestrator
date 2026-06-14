<?php

declare(strict_types=1);

namespace Orchestrator\Http\Controller;

use Orchestrator\Http\Response\JsonResponse;
use Orchestrator\Task\TaskService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TaskController
{
    public function __construct(
        private readonly TaskService $taskService,
    ) {
    }

    public function listTasks(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->taskService->listAllTasks();

        return JsonResponse::success($response, $result);
    }

    public function handleTask(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $objectType = $args['objectType'] ?? null;
        $method = $request->getMethod();
        $jsonBody = $request->getParsedBody();

        try {
            $result = $this->taskService->handleTask($method, $objectType, $jsonBody);
        } catch (\RuntimeException $e) {
            return JsonResponse::error($response, $e->getMessage(), $e->getCode() ?: 400);
        }

        return JsonResponse::success($response, $result);
    }

    public function removeSubTasks(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->taskService->removeSubTasks();

        return JsonResponse::success($response, $result);
    }

    public function activateCyclicTasks(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->taskService->activateCyclicTasks();

        return JsonResponse::success($response, $result);
    }

    public function restartFailedTasks(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $jsonBody = $request->getParsedBody();
        $taskName = $jsonBody['taskName'] ?? null;

        $result = $this->taskService->restartFailedTasks($taskName);

        return JsonResponse::success($response, $result);
    }
}
