<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Internals\RabbitMq\IRabbitMqMessageHandler;
use Internals\RabbitMq\RabbitMqConnection;
use Internals\RabbitMq\RabbitMqPublisher;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MessageHandler implements IRabbitMqMessageHandler
{
    /** @var string | null */
    protected $capital;

    /** @var string | null */
    protected $country;

    /** @var LoggerInterface */
    protected $logger;

    /** @var Client */
    protected $httpClient;

    /** @var RabbitMqConnection */
    protected $rabbitMqConnection;

    /** @codeCoverageIgnore */
    public function __construct(
        RabbitMqConnection $rabbitMqConnection,
        LoggerInterface $logger = null
    ) {
        $this->rabbitMqConnection = $rabbitMqConnection;
        $this->logger = $logger ?: new NullLogger();

        $this->httpClient = new Client(['base_uri' => API_WEATHER_BASE_URI]);

        $this->capital = null;
        $this->country = null;
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

            if (!isset($payload["capital"])) {
                return false;
            }

            $this->capital = trim($payload["capital"]);
            $this->country = trim($payload["country"] ?? "Unknown");

            return !empty($this->capital);
        } catch (\JsonException) {
            return false;
        }
    }

    /**
     * Consume a message, having a validated structure, from RabbitMQ.
     *
     * @param string $payload The message body
     * @param array $properties Message properties
     * @return bool TRUE if the message could have been consumed, FALSE otherwise.
     * @throws MessageHandlerException If an exception is thrown, message will be requeued.
     */
    public function consume(string $payload, array $properties): bool
    {
        if ($this->capital === null || $this->country === null) {
            return false;
        }

        /** @var ResponseInterface | null */
        $response = null;

        try {
            $response = $this->httpClient
                ->request('GET', 'api/weather/external/' . urlencode($this->capital), [
                    'allow_redirects' => false,
                    'connect_timeout' => 5,
                    'timeout' => 5
                ]);
        } catch (RequestException $e) {
            $this->logger->debug($e->getMessage());

            $statusCode = $e->getResponse()?->getStatusCode() ?? null;

            $this->logger->warning(
                'Weather API has returned code error ' . ($statusCode) .
                    ' for capital "' . ($this->capital) . '"'
            );

            if ($statusCode === null) {
                throw new MessageHandlerException('No response from the Weather API.');
            }

            if ($statusCode >= 500 || ($statusCode >= 300 && $statusCode < 400)) {
                throw new MessageHandlerException('Incorrect behavior from the Weather API');
            }

            // For 4xx errors, we skip the message
            return false;
        }

        try {
            $weatherData = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->debug($e->getMessage());
            throw new MessageHandlerException(
                'Weather API has returned malformed data for capital: "' . ($this->capital) . '".'
            );
        }

        $enrichedMessage = [
            'capital' => $this->capital,
            'country' => $this->country,
            'weather' => $weatherData
        ];

        $this->publishWeatherResult($enrichedMessage);

        return true;
    }

    /**
     * Publish enriched message to the weather results queue.
     *
     * @codeCoverageIgnore
     * @param array $message The enriched message with weather data
     * @throws MessageHandlerException
     */
    public function publishWeatherResult(array $message): void
    {
        try {
            (new RabbitMqPublisher($this->rabbitMqConnection, $this->logger))
                ->publish(QUEUE_OUT, json_encode($message));
        } catch (\Exception $e) {
            throw new MessageHandlerException($e->getMessage());
        }
    }
}
