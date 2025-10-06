<?php

declare(strict_types=1);

namespace App;

use Internals\RabbitMq\IRabbitMqMessageHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MessageHandler implements IRabbitMqMessageHandler
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?: new NullLogger();
    }

    public function validate(string $body): bool
    {
        return true;
    }

    public function consume(string $payload): bool
    {
        // By specifications, we don't have to do something in particular,
        // here. Let's keep it simple!
        $this->logger->debug($payload);
        return true;
    }
}
