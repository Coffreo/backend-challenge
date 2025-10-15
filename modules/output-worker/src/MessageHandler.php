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

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: new NullLogger();
    }

    /**
     * Validate the structure and contents of the message.
     *
     * @param string $body The message body
     * @param array $properties Message properties
     * @return bool TRUE if the message has been validated, FALSE otherwise.
     */
    public function validate(string $body, array $properties): bool
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return isset($payload["capital"]) &&
                   isset($payload["country"]) &&
                   isset($payload["weather"]);
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * Consume a message, having a validated structure, from RabbitMQ.
     * This is the final worker in the pipeline - it displays the results.
     *
     * @param string $payload The message body
     * @param array $properties Message properties
     * @return bool TRUE if the message could have been consumed, FALSE otherwise.
     */
    public function consume(string $payload, array $properties): bool
    {
        try {
            // Validate JSON structure first
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            
            $this->logger->info("[challenge-pipeline] Step 5/5: Final result ready");

            // Log pipeline result
            $this->logger->info("[challenge-pipeline] End: Pipeline completed successfully {$payload}");

            return true;
        } catch (\JsonException $e) {
            $this->logger->error('Invalid JSON in final message: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Failed to process final weather message: ' . $e->getMessage());
            return false;
        }
    }
}
