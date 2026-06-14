<?php

declare(strict_types=1);

$dotenv = Dotenv\Dotenv::createImmutable(CONFIG_DIR, CONFIG_FILE);
$dotenv->load();
