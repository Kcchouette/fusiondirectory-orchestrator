<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = (new Orchestrator\Application\AppFactory())->create();
$app->run();
