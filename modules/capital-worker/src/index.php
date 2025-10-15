<?php

declare(strict_types=1);

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use Internals\RabbitMq\RabbitMqConnection;
use Internals\RabbitMq\RabbitMqConsumer;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

define('WORKER_ID', uniqid('worker-capitals'));

define('QUEUE_IN', $_ENV['RABBITMQ_QUEUE_CAPITALS']);
define('QUEUE_OUT', $_ENV['RABBITMQ_QUEUE_CAPITALS_PROCESSED']);

/** @var Logger */
$logger = new Logger(WORKER_ID);
$logger->pushHandler(new StreamHandler('php://stdout'), Level::Debug);
$logger->pushProcessor(function ($record) {
    $record->extra['worker_id'] = WORKER_ID;
    return $record;
});

try {
    /** @var RabbitMqConnection */
    $rabbitMqConnection = new RabbitMqConnection($logger);
    $rabbitMqConnection->connect();

    (new RabbitMqConsumer(WORKER_ID, $rabbitMqConnection, $logger))
        ->listen(QUEUE_IN, new MessageHandler($rabbitMqConnection, $logger));
} catch (\Exception $e) {
    // We know that wa got a fatal error level at this point.
    // Thus, it helps detecting edge cases.
    $logger->error($e->getMessage());
}
