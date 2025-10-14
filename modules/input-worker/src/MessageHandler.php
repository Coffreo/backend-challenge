<?php

declare(strict_types=1);

namespace App;

use Internals\RabbitMq\IRabbitMqMessageHandler;
use Internals\RabbitMq\RabbitMqConnection;
use Internals\RabbitMq\RabbitMqPublisher;
use Internals\RabbitMq\RabbitMqConsumer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles incoming messages and implements intelligent routing strategy.
 *
 * Routes messages first to countries queue, then monitors for failures
 * and republishes to capitals queue when needed.
 */
class MessageHandler implements IRabbitMqMessageHandler
{
    /** @var string|null Cached value from validation */
    protected $value;

    /** @var string|null Current correlation ID for RPC tracking */
    protected $correlationId;


    /** @var LoggerInterface */
    protected $logger;

    /** @var RabbitMqConnection */
    protected $rabbitMqConnection;

    /** @var RabbitMqPublisher */
    protected $publisher;

    public function __construct(RabbitMqConnection $rabbitMqConnection, ?LoggerInterface $logger = null)
    {
        $this->rabbitMqConnection = $rabbitMqConnection;
        $this->logger = $logger ?: new NullLogger();
        $this->publisher = new RabbitMqPublisher($this->rabbitMqConnection, $this->logger);
        $this->value = null;
        $this->correlationId = null;
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

            if (!isset($payload['value']) || !is_string($payload['value'])) {
                return false;
            }

            $value = trim($payload['value']);
            if (empty($value)) {
                return false;
            }

            $this->value = $value;

            return true;
        } catch (\JsonException $e) {
            $this->logger->warning('Invalid JSON in message: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Consume a message, having a validated structure, from RabbitMQ.
     *
     * Implements request-response routing strategy:
     * 1. First attempt: route to countries queue
     * 2. Monitor response queue for success/failure
     * 3. If failure detected: republish to capitals queue
     *
     * @param string $payload The message body
     * @param array $properties Message properties
     * @return bool TRUE if the message could have been consumed, FALSE otherwise.
     */
    public function consume(string $payload, array $properties): bool
    {
        if ($this->value === null) {
            return false;
        }

        try {
            $this->correlationId = uniqid('input-worker-', true);

            $this->logger->info("Routing value '{$this->value}' to countries queue", [
                'correlation_id' => $this->correlationId
            ]);
            $this->publishToCountries($this->value);

            $this->waitForCountryResponse($this->value);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to process message: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Publishes message to countries queue with response tracking.
     *
     * @param string $value The value to send
     */
    protected function publishToCountries(string $value): void
    {
        $message = json_encode([
            'country_name' => $value
        ]);

        $properties = [
            'correlation_id' => $this->correlationId,
            'reply_to' => QUEUE_COUNTRIES_RESPONSES,
            'message_id' => uniqid('msg-', true)
        ];

        $this->publisher->publish(QUEUE_COUNTRIES, $message, $properties);
    }

    /**
     * Publishes message to capitals queue.
     *
     * @param string $value The value to send
     */
    protected function publishToCapitals(string $value): void
    {
        $message = json_encode(['capital_name' => $value]);
        $this->publisher->publish(QUEUE_CAPITALS, $message);
    }

    /**
     * Monitors response queue for country worker reply.
     *
     * Implements synchronous request-response with timeout:
     * - Status "success" → Request completed (country found)
     * - Status "failure" → Republish to capitals queue
     * - Timeout → Request completed (no republishing)
     *
     * @param string $originalValue The original value we sent
     */
    protected function waitForCountryResponse(string $originalValue): void
    {
        $timeout = 10;

        $this->logger->info("Waiting for country worker response with timeout", [
            'correlation_id' => $this->correlationId,
            'value' => $originalValue,
            'timeout' => $timeout
        ]);

        $responseHandler = new CountryResponseHandler($this->correlationId, $this, $this->logger);

        try {
            $consumerTag = uniqid('input-response');
            $consumer = new RabbitMqConsumer($consumerTag, $this->rabbitMqConnection, $this->logger);

            $consumer->listen(QUEUE_COUNTRIES_RESPONSES, $responseHandler, $timeout);

        } catch (\Exception $e) {
            $this->logger->error('Error while waiting for country worker response: ' . $e->getMessage(), [
                'correlation_id' => $this->correlationId
            ]);

        }
    }

    /**
     * Handles republishing to capitals queue when country lookup fails.
     *
     * @param string $value The value to republish as capital
     */
    public function republishToCapitals(string $value): void
    {
        $this->logger->info("Country lookup failed for '{$value}', republishing to capitals queue");
        $this->publishToCapitals($value);
    }
}

