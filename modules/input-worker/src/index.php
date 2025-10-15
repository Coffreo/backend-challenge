<?php

declare(strict_types=1);

namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use Internals\RabbitMq\RabbitMqConnection;
use Internals\RabbitMq\RabbitMqConsumer;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * In an ideal word, we'd have assigned an unique worker id, as we are able
 * to have multiple workers doing the same task.
 * Here, it is represented as a uniqid, but we may expect some orchestrator
 * managing this, behind.
 */
define('WORKER_ID', uniqid('worker-input'));

define('QUEUE_INPUT', $_ENV['RABBITMQ_QUEUE_INPUT']);
define('QUEUE_COUNTRIES', $_ENV['RABBITMQ_QUEUE_COUNTRIES']);
define('QUEUE_CAPITALS', $_ENV['RABBITMQ_QUEUE_CAPITALS']);
define('QUEUE_COUNTRIES_RESPONSES', $_ENV['RABBITMQ_QUEUE_COUNTRIES_RESPONSES']);

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
        ->listen(QUEUE_INPUT, new MessageHandler($rabbitMqConnection, $logger));
} catch (\Exception $e) {
    // We know that wa got a fatal error level at this point.
    // Thus, it helps detecting edge cases.
    $logger->error($e->getMessage());
}
