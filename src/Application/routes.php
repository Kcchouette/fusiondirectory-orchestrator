<?php

declare(strict_types=1);

use Orchestrator\Http\Controller\AuthController;
use Orchestrator\Http\Controller\TaskController;
use Orchestrator\Http\Middleware\JwtAuthenticationMiddleware;
use Slim\Routing\RouteCollectorProxy;

return function (Slim\App $app) {

    $app->post('/api/login', [AuthController::class, 'login']);
    $app->post('/api/logout', [AuthController::class, 'logout']);
    $app->post('/api/refresh', [AuthController::class, 'refresh']);

    $app->group('/api', function (RouteCollectorProxy $group) {
        $group->get('/tasks', [TaskController::class, 'listTasks']);
        $group->get('/tasks/{objectType}', [TaskController::class, 'handleTask']);
        $group->post('/tasks/{objectType}', [TaskController::class, 'handleTask']);
        $group->patch('/tasks/{objectType}', [TaskController::class, 'handleTask']);
        $group->patch('/tasks/removeSubTasks', [TaskController::class, 'removeSubTasks']);
        $group->patch('/tasks/activateCyclicTasks', [TaskController::class, 'activateCyclicTasks']);
        $group->patch('/tasks/restartFailedTasks', [TaskController::class, 'restartFailedTasks']);
    })->add(JwtAuthenticationMiddleware::class);
};
