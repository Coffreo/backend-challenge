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
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            $capital = $data['capital'];
            $country = $data['country'];
            $weather = $data['weather'];

            $output = sprintf(
                "FINAL RESULT: %s (capital of %s)\n" .
                "Temperature: %d°C\n" .
                "Condition: %s\n" .
                "Humidity: %d%%\n" .
                "Wind Speed: %d km/h\n" .
                "Timestamp: %s",
                $capital,
                $country,
                $weather['temperature'],
                $weather['condition'],
                $weather['humidity'],
                $weather['wind_speed'],
                $weather['timestamp']
            );

            // Log the formatted result
            $this->logger->info($output);

            // Also log the raw JSON for debugging purposes
            $this->logger->debug('Raw weather data: ' . $payload);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to process final weather message: ' . $e->getMessage());
            return false;
        }
    }
}
