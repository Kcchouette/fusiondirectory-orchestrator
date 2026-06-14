<?php

declare(strict_types=1);

namespace Orchestrator\Application;

use DI\ContainerBuilder;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

class AppFactory
{
    public function create(): App
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(__DIR__ . '/../../config/dependencies.php');
        $container = $containerBuilder->build();

        SlimAppFactory::setContainer($container);

        $app = SlimAppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(true, true, true);

        require __DIR__ . '/routes.php';

        return $app;
    }
}
