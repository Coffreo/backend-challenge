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
    /**
     * Cached country extracted from the validator.
     *
     * @var string | null */
    protected $country;

    /** @var LoggerInterface */
    protected $logger;

    /** @var Client */
    protected $httpClient;

    /** @var RabbitMqConnection */
    protected $rabbitMqConnection;

    /**
     * Poor man's cache. The fact is that we know that the total volume of
     * VALID data (= number of countries in the world) is fairly small.
     * We could have implemented a poor's man TTL, where we can reset the cache
     * each week.
     * For production application, we may consider a shared Redis node, for
     * instance, to share cache between multiple worker pods.
     *
     * @var array<string, string> */
    protected static $poorManCache = [];

    /** @codeCoverageIgnore */
    public function __construct(
        RabbitMqConnection $rabbitMqConnection,
        LoggerInterface $logger = null
    ) {
        $this->rabbitMqConnection = $rabbitMqConnection;
        $this->logger = $logger ?: new NullLogger();

        // This could have been generalized using PSR interface, but, hey,
        // that's just a test.
        $this->httpClient = new Client(['base_uri' => RESTCOUNTRIES_BASE_URI]);

        $this->country = null;
    }

    /**
     * Validate the structure and contents of the message.
     *
     * @param string $body The message body
     * @return bool TRUE if the message has been validated, FALSE otherwise.
     */
    public function validate(string $body): bool
    {
        $payload = ["country_name" => ""];

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($payload["country_name"])) return false;
        } catch (\JsonException) {
            return false;
        }

        $country = trim(strtolower($payload["country_name"]));

        // The API seems to have troubles handling non latin characters.
        // So, pretty roughly, we fail on non latin detection.
        // https://www.php.net/manual/fr/regexp.reference.unicode.php
        // It helps handling edge cases for this API with names like 日本 (Japan)
        // (urlencoded as %E6%97%A5%E6%9C%AC), which is not recognized by the API.
        if (!preg_match('`^[\p{Latin}\s]+$`u', $country)) {
            return false;
        }

        // This is a bit dirty in terms of flow, but we prefer saving some
        // CPU cycles (ok, in PHP, but still) instead of redoing the exact
        // same operations.
        $this->country = $country;

        return true;
    }

    /**
     * Consume a message, having a validated structure, from RabbitMQ.
     *
     * @param string $payload The message body
     * @param bool TRUE if the message could have been consumed, FALSE otherwise.
     * @throws MessageHandlerException If an exception is thrown, message will be requeued.
     */
    public function consume(string $payload): bool
    {
        if ($this->country === null) return false;

        /** @var string | null */
        $capital = self::$poorManCache[$this->country] ?? null;

        if ($capital !== null) {
            $this->publish($capital);
            return true;
        }

        /** @var ResponseInterface | null */
        $response = null;

        try {
            $response = $this->httpClient
                ->request('GET', 'name/' . $this->country, [
                    'allow_redirects' => false,
                    'connect_timeout' => 5,
                    'timeout' => 5
                ]);
        } catch (RequestException $e) {
            $this->logger->debug($e->getMessage());

            $statusCode = $e->getResponse()?->getStatusCode() ?? null;

            $this->logger->warning(
                'API has returned code error ' . ($statusCode) .
                    ' for value "' . ($this->country)
            );

            if ($statusCode === null) {
                throw new MessageHandlerException('No response from the HTTP client.');
            }

            if ($statusCode >= 500 || ($statusCode >= 300 && $statusCode < 400)) {
                throw new MessageHandlerException('Incorrect behavior from the API provider: ');
            }

            // In this case, we are likely to have a 400-ish error, which
            // is an error from the requester. As it comes from our side, we
            // consider it as error, or noisy data, and remove it from the queue.
            // One particular case, though, would be having a 429 HTTP Error (Too Many Request).
            // As we may consider multiple pods running, we may put in a Redis a
            // dynamic slow down.
            return false;
        }

        try {
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $capital = $body[0]['capital'][0];
        } catch (\Throwable $e) {
            $this->logger->debug($e->getMessage());
            throw new MessageHandlerException(
                'API has returned malformed or unexpected data for value: "'
                    . ($this->country) . '".'
            );
        }

        self::$poorManCache[$this->country] = $capital;

        $this->publish($capital);

        return true;
    }

    /**
     * Publish a message to a specific RabbitMq queue for capitals.
     *
     * @codeCoverageIgnore
     * @param string $capital The capital we want to write to the queue
     * @throws MessageHandlerException
     */
    public function publish(string $capital): void
    {
        try {
            (new RabbitMqPublisher($this->rabbitMqConnection, $this->logger))
                ->publish(QUEUE_OUT, $capital);
        } catch (\Exception $e) {
            throw new MessageHandlerException($e->getMessage());
        }
    }
}
