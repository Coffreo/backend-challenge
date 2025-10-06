<?php

declare(strict_types=1);

namespace App;

use Internals\RabbitMq\RabbitMqConnection;

define('EXIT_SUCCESS', 0);
define('EXIT_FAILURE', 1);

require_once __DIR__ . '/../vendor/autoload.php';

try {
    if ((new RabbitMqConnection())->isConnected()) {
        exit(EXIT_SUCCESS);
    }
} catch (\Exception) {
}

exit(EXIT_FAILURE);
