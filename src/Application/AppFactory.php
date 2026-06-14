<?php

declare(strict_types=1);

namespace Orchestrator\Application;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

class AppFactory
{
    public function create(): App
    {
        $this->loadEnv();

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

    private function loadEnv(): void
    {
        $paths = [
            __DIR__ . '/../../.env',
            '/etc/fusiondirectory-orchestrator/orchestrator.conf',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $dotenv = Dotenv::createImmutable(dirname($path));
                $dotenv->load();
                return;
            }
        }
    }
}
