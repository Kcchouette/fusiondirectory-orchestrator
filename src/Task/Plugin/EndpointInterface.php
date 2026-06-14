<?php

declare(strict_types=1);

namespace Orchestrator\Task\Plugin;

use Orchestrator\Task\TaskGateway;

interface EndpointInterface
{
    public function __construct(TaskGateway $gateway);

    public function processEndPointGet(): array;

    public function processEndPointPost(?array $data = null): array;

    public function processEndPointPatch(?array $data = null): array;

    public function processEndPointDelete(?array $data = null): array;
}
