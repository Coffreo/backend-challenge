<?php

declare(strict_types=1);

namespace Internals\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
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
     * @see https://www.rabbitmq.com/docs/amqp-0-9-1-reference#basic.consume
     */
    public function listen(
        string $queue,
        IRabbitMqMessageHandler $handler
    ): void {
        $this->connection->execute(
            fn (AMQPChannel $channel) =>
            $this->consume($channel, $queue, $handler)
        );
    }

    private function consume(
        AMQPChannel $channel,
        string $queue,
        IRabbitMqMessageHandler $handler
    ): void {
        $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => 'dlx',
            //'x-message-ttl' => 15000,
            //'x-expires' => 16000
        ]));

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
            if (!$handler->validate($amqpMessage->body)) {
                $amqpMessage->nack(false);

                $this->logger->info('Message "' . ($amqpMessage->body) .
                    '" rejected.');

                return;
            }

            if ($handler->consume($amqpMessage->body)) {
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
