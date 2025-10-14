<?php

declare(strict_types=1);

namespace Internals\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @codeCoverageIgnore
 */
class RabbitMqConsumer
{
    /** @var RabbitMqConnection */
    private $connection;

    /** @var string */
    private $consumerIdentifier;

    /** @var LoggerInterface */
    private $logger;


    public function __construct(
        string $consumerIdentifier,
        RabbitMqConnection $rabbitMqConnection,
        LoggerInterface $logger = null
    ) {
        $this->connection = $rabbitMqConnection;
        $this->consumerIdentifier = $consumerIdentifier;

        $this->logger = $logger ?: new NullLogger();
    }

    /**
     * Listen to messages from a given queue.
     *
     * @param string $queue A durable queue.
     * @param IRabbitMqMessageHandler $handler A class that will handle received messages.
     * @param int $timeout Timeout in seconds to wait for a message (0 = no timeout)
     * @see https://www.rabbitmq.com/docs/amqp-0-9-1-reference#basic.consume
     */
    public function listen(
        string $queue,
        IRabbitMqMessageHandler $handler,
        int $timeout = 0
    ): void {
        $this->connection->execute(
            fn (AMQPChannel $channel) =>
            $this->consume($channel, $queue, $handler, $timeout)
        );
    }

    private function consume(
        AMQPChannel $channel,
        string $queue,
        IRabbitMqMessageHandler $handler,
        int $timeout = 0
    ): void {
        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => 'dlx',
            //'x-message-ttl' => 15000,
            //'x-expires' => 16000
        ]));

        if ($timeout > 0) {
            $this->consumeWithTimeout($channel, $queue, $handler, $timeout);
        } else {
            $this->consumeWithoutTimeout($channel, $queue, $handler);
        }
    }

    private function consumeWithTimeout(
        AMQPChannel $channel,
        string $queue,
        IRabbitMqMessageHandler $handler,
        int $timeout
    ): void {
        $messageProcessed = false;

        $channel->basic_consume(
            $queue,
            $this->consumerIdentifier,
            false,
            false,
            false,
            false,
            function (AMQPMessage $amqpMessage) use ($handler, &$messageProcessed) {
                $this->handleListenerCallback($amqpMessage, $handler);
                $messageProcessed = true;
                $amqpMessage->getChannel()->basic_cancel($this->consumerIdentifier);
            }
        );

        $endTime = time() + $timeout;

        while ($channel->is_consuming() && time() < $endTime && !$messageProcessed) {
            try {
                $remainingTime = $endTime - time();
                if ($remainingTime <= 0) {
                    break;
                }
                $channel->wait(null, false, min($remainingTime, 1));
            } catch (AMQPTimeoutException $e) {
                // Timeout is expected, continue checking
                continue;
            } catch (\Exception $e) {
                $this->logger->warning('Error while waiting for message: ' . $e->getMessage());
                break;
            }
        }

        if ($channel->is_consuming()) {
            $channel->basic_cancel($this->consumerIdentifier);
        }
    }

    private function consumeWithoutTimeout(
        AMQPChannel $channel,
        string $queue,
        IRabbitMqMessageHandler $handler
    ): void {
        $channel->basic_consume(
            $queue,
            $this->consumerIdentifier,
            false,
            false,
            false,
            false,
            fn (AMQPMessage $amqpMessage) => $this->handleListenerCallback(
                $amqpMessage,
                $handler
            )
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    private function handleListenerCallback(
        AMQPMessage $amqpMessage,
        IRabbitMqMessageHandler $handler
    ): void {
        try {
            // Extract AMQP properties
            $properties = $amqpMessage->get_properties();

            if (!$handler->validate($amqpMessage->body, $properties)) {
                $amqpMessage->nack(false);

                $this->logger->info('Message "' . ($amqpMessage->body) .
                    '" rejected.');

                return;
            }

            if ($handler->consume($amqpMessage->body, $properties)) {
                $amqpMessage->ack();
            } else {
                // In this case, the consumption failed "explicitly".
                // The message can't be processed, thus will be rejected.
                $amqpMessage->nack(false);
            }
        } catch (\Exception $e) {
            $amqpMessage->nack(true);
            throw new RabbitMqException($e->getMessage());
        }
    }
}
