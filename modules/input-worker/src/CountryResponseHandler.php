<?php

declare(strict_types=1);

namespace App;

use Internals\RabbitMq\IRabbitMqMessageHandler;
use Psr\Log\LoggerInterface;

/**
 * Handler for processing country worker responses from countries_responses queue.
 */
class CountryResponseHandler implements IRabbitMqMessageHandler
{

    /** @var string Expected correlation ID for response filtering */
    protected $expectedCorrelationId;

    /** @var MessageHandler Input handler for actions */
    protected $inputHandler;

    /** @var LoggerInterface */
    protected $logger;

    /** @var array|null Cached response data from validation */
    protected $responseData;

    /**
     * @param string $expectedCorrelationId The correlation ID we're waiting for
     * @param MessageHandler $inputHandler Handler for actions
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(string $expectedCorrelationId, MessageHandler $inputHandler, LoggerInterface $logger)
    {
        $this->expectedCorrelationId = $expectedCorrelationId;
        $this->inputHandler = $inputHandler;
        $this->logger = $logger;
    }

    /**
     * Validate the structure and contents of the message.
     *
     * Expected format:
     * - Success: {"success": true}
     * - Failure: {"success": false, "invalid_country": "Paris"}
     *
     * Only processes responses matching our correlation_id.
     *
     * @param string $body The message body
     * @param array $properties Message properties
     * @return bool TRUE if the message has been validated, FALSE otherwise.
     */
    public function validate(string $body, array $properties): bool
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            // Required fields
            if (!isset($payload['success']) || !is_bool($payload['success'])) {
                return false;
            }

            $this->responseData = $payload;
            $correlationId = $properties['correlation_id'] ?? null;

            // Only process responses matching our correlation_id
            if ($correlationId === $this->expectedCorrelationId) {
                $this->logger->debug("correlation ID match", [
                    'correlation_id' => $correlationId
                ]);
                return true;
            }

            $this->logger->debug("correlation ID mismatch", [
                'expected' => $this->expectedCorrelationId,
                'received' => $correlationId
            ]);

            return false;
        } catch (\JsonException $e) {
            $this->logger->warning('Invalid response message JSON: ' . $e->getMessage(), ['body' => $body]);
            return false;
        }
    }

    /**
     * Consume a message, having a validated structure, from RabbitMQ.
     *
     * @param string $payload The message body
     * @param array $properties Message properties
     * @return bool TRUE if the message could have been consumed, FALSE otherwise.
     */
    public function consume(string $payload, array $properties): bool
    {
        if ($this->responseData === null) {
            return false;
        }

        $success = $this->responseData['success'];
        $correlationId = $properties['correlation_id'] ?? null;

        if (!$success) {
            $invalidCountry = $this->responseData['invalid_country'] ?? null;

            $this->logger->info("Country worker failure - Republishing to capitals", [
                'correlation_id' => $correlationId,
                'invalid_country' => $invalidCountry
            ]);

            // Failure: republish to capitals queue
            if ($invalidCountry !== null) {
                $this->inputHandler->republishToCapitals($invalidCountry);
            }
        }

        return true;
    }
}
