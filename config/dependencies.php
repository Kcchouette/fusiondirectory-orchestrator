<?php

declare(strict_types=1);

use Orchestrator\Auth\JWTCodec;
use Orchestrator\Auth\RefreshTokenGateway;
use Orchestrator\Auth\UserGateway;
use Orchestrator\Http\Controller\AuthController;
use Orchestrator\Http\Controller\TaskController;
use Orchestrator\Http\Middleware\JwtAuthenticationMiddleware;
use Orchestrator\Ldap\Ldap;
use Orchestrator\Ldap\Backend;
use Orchestrator\Task\TaskGateway;
use Orchestrator\Task\TaskService;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;

return [
    ResponseFactoryInterface::class => DI\autowire(ResponseFactory::class),

    JWTCodec::class => DI\factory(fn () => new JWTCodec($_ENV['SECRET_KEY'])),

    Ldap::class => DI\factory(fn () => new Ldap(
        $_ENV['LDAP_URI'],
        $_ENV['LDAP_BIND_DN'],
        $_ENV['LDAP_PASSWORD'],
    )),

    Backend::class => DI\autowire(),

    UserGateway::class => DI\autowire(),

    RefreshTokenGateway::class => DI\autowire(),

    TaskGateway::class => DI\autowire(),

    TaskService::class => DI\autowire(),

    AuthController::class => DI\autowire(),

    TaskController::class => DI\autowire(),

    JwtAuthenticationMiddleware::class => DI\autowire(),
];
