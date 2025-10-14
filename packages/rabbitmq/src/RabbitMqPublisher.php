<?php

declare(strict_types=1);

namespace Internals\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;

/**
 * @codeCoverageIgnore
 */
class RabbitMqPublisher
{
    /** @var RabbitMqConnection */
    private $connection;

    /** @var LoggerInterface | null */
    private $logger;

    public function __construct(RabbitMqConnection $rabbitMqConnection, LoggerInterface $logger = null)
    {
        $this->connection = $rabbitMqConnection;
        $this->logger = $logger;
    }

    /**
     * Publishes a message to a specific queue.
     *
     * @param string $queue The queue the message will be pushed on.
     * @param string $messageBody Contents of the so-called message.
     * @param array $messageProperties Optional AMQP message properties (correlation_id, reply_to, etc.)
     */
    public function publish(string $queue, string $messageBody, array $messageProperties = []): void
    {
        // Default properties
        $defaultProperties = [
            "content_type" => "text/plain",
            "delivery_mode" => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ];

        // Merge with custom properties (custom properties override defaults)
        $properties = array_merge($defaultProperties, $messageProperties);

        /** @var AMQPMessage */
        $message = new AMQPMessage($messageBody, $properties);

        /** @var string */
        $exchange = 'router';

        $this->connection->execute(
            function (AMQPChannel $channel) use ($queue, $message, $exchange) {
                $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
                    'x-dead-letter-exchange' => 'dlx',
                    //'x-message-ttl' => 15000,
                    //'x-expires' => 16000
                ]));

                $channel->exchange_declare($exchange, AMQPExchangeType::DIRECT, false, true, false);

                // Use queue-specific routing key to ensure messages are delivered only to the intended queue
                // Without this, all queues bound to the same exchange would receive the same message
                $routingKey = $queue . '_routing_key';
                $channel->queue_bind($queue, $exchange, $routingKey);
                $channel->basic_publish($message, $exchange, $routingKey);
            }
        );

        $this->logger->debug('Message "' . $messageBody . '" published on queue "' . $queue . '"');
    }
}
