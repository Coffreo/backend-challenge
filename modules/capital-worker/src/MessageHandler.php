<?php

declare(strict_types=1);

namespace App;

use Internals\RabbitMq\IRabbitMqMessageHandler;
use Internals\RabbitMq\RabbitMqConnection;
use Internals\RabbitMq\RabbitMqPublisher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MessageHandler implements IRabbitMqMessageHandler
{
    /** @var LoggerInterface */
    private $logger;

    /** @var RabbitMqConnection */
    private $rabbitMqConnection;

    public function __construct(
        RabbitMqConnection $rabbitMqConnection,
        LoggerInterface $logger = null
    ) {
        $this->rabbitMqConnection = $rabbitMqConnection;
        $this->logger = $logger ?: new NullLogger();
    }

    public function validate(string $body, array $properties): bool
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return isset($payload["capital"]);
        } catch (\JsonException) {
            return false;
        }
    }

    public function consume(string $payload, array $properties): bool
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            // Ensure we have both capital and country for weather-worker
            $processedData = [
                'capital' => $data['capital'],
                'country' => $data['country'] ?? 'Unknown', // Default to Unknown if country not provided
                'processed' => true
            ];

            // Publish to capitals_processed queue
            (new RabbitMqPublisher($this->rabbitMqConnection, $this->logger))
                ->publish(QUEUE_OUT, json_encode($processedData));

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to process capital message: ' . $e->getMessage());
            return false;
        }
    }
}
