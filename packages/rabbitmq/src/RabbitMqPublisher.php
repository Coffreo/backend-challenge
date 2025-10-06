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
     */
    public function publish(string $queue, string $messageBody): void
    {
        /** @var AMQPMessage */
        $message = new AMQPMessage($messageBody, [
            "content_type" => "text/plain",
            "delivery_mode" => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);

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
                $channel->queue_bind($queue, $exchange);
                $channel->basic_publish($message, $exchange);
            }
        );

        $this->logger->debug('Message "' . $messageBody . '" published on queue "' . $queue . '"');
    }
}
