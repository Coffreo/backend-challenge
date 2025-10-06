<?php

declare(strict_types=1);

namespace Internals\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RabbitMqConnection
{
    /** @var AMQPStreamConnection | NULL */
    private $amqpConnection;

    /** @var LoggerInterface */
    private $logger;

    /** @var int */
    const MAX_CONNECTION_ATTEMPTS = 5;

    /**
     * When executing procedures, if we face multiple consecutive timeout,
     * we will end gracefully.
     *
     * @var int
     */
    const MAX_CONSECUTIVE_TIMEOUTS = 3;

    /**
     * When executing procedures, we may face multiple errors of any kind.
     * To add a overconstraint and secure, we put a max number of looping to
     * avoid any potential infinite loop (the container will be restarted at
     * the end).
     *
     * @var int
     */
    const MAX_PROCEDURES_ATTEMPTS = 30;

    /**
     * @codeCoverageIgnore
     */
    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?: new NullLogger();
    }

    /**
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        try {
            $this->amqpConnection->close();
        } catch (\Throwable) {
        } finally {
            // Hey, GC, that's for you.
            $this->amqpConnection = null;
        }
    }

    /**
     * Create or retrieves an existing channel, based on its id.
     * As we follow a defensive approach, if a channel with a channelId isn't
     * available, we fetch a new channel, with a different id.
     *
     * @param int $channelId Identifier of a channel.
     * @return AMQPChannel Newly created or retrieved channel object.
     * @throws RabbitMqException In case of any problem impossible to solve, fail.
     */
    public function channel(int | null $channelId = null): AMQPChannel
    {
        /** @var int */
        $attempts = 1;

        /** @var AMQPChannel | null */
        $channel = null;

        if ($channelId !== null) {
            $this->logger->debug('Trying to open channel of id ' . $channelId);
        }

        do {
            // @codeCoverageIgnoreStart
            try {
                $channel = $this->amqpConnection->channel($channelId);
                break;
            } catch (AMQPConnectionClosedException $e) {
                $this->logger->warning('Connection closed while opening channel. Trying to reconnect.');
                $this->connect();
            } catch (AMQPTimeoutException $e) {
                $this->logger->warning('Timeout while opening channel. Retrying in 3s.');
                sleep(3);
            } catch (\Exception $e) {
                $this->logger->debug($e);
                throw new RabbitMqException('Error while opening a channel.');
            } finally {
                $attempts++;

                // Maybe that a channel might not be available, or is timing out.
                if ($channelId !== null) {
                    $channelId = null;
                }
            }
            // @codeCoverageIgnoreEnd
        } while ($attempts <= 3);

        if (!$channel || !$channel->is_open()) {
            throw new RabbitMqException('Unable to open a channel.');
        }

        $this->logger->debug('Opened channel ' . $channel->getChannelId());

        return $channel;
    }

    /**
     * Returns whether or not we are connected to RabbitMQ server.
     *
     * @return bool TRUE if we are connected, FALSE otherwise.
     */
    public function isConnected(): bool
    {
        return $this->amqpConnection && $this->amqpConnection->isConnected();
    }

    /**
     * (Re)connects to a RabbitMQ server.
     * 
     * @codeCoverageIgnore
     */
    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        if (
            !isset($_ENV['RABBITMQ_HOST']) ||
            !isset($_ENV['RABBITMQ_PORT']) ||
            !isset($_ENV['RABBITMQ_USER']) ||
            !isset($_ENV['RABBITMQ_PASSWORD'])
        ) {
            throw new RabbitMqException('Missing RabbitMQ environment variable.');
        }

        $attempts = 1;

        do {
            $this->logger->info('Connecting to RabbitMQ server (attempt: ' .
                $attempts . ')');

            try {
                $this->amqpConnection = new AMQPStreamConnection(
                    $_ENV['RABBITMQ_HOST'],
                    (int) $_ENV['RABBITMQ_PORT'],
                    $_ENV['RABBITMQ_USER'],
                    $_ENV['RABBITMQ_PASSWORD']
                );
            } catch (\Exception $e) {
                $this->logger->debug($e->getMessage());

                $this->logger->warning(
                    'Error while trying to connect to RabbitMQ server. Retrying in ' .
                        $attempts . 's.'
                );

                // Poor's man method, but application with wait a bit longer
                // in order to try to gracefully recover.
                sleep($attempts);
            }

            $attempts++;
        } while (!$this->isConnected() && $attempts <= self::MAX_CONNECTION_ATTEMPTS);

        if (!$this->isConnected()) {
            throw new RabbitMqException('Failed connecting to RabbitMQ server.');
        } else {
            $this->logger->info('Connection to RabbitMQ server successful.');
        }
    }

    /**
     * Secure execution context for AMQP procedures.
     * Get a channel and handles potential bugs, timeout or crashes with
     * graceful ends or recovery attemps.
     *
     * @param callable $rabbitMqProcedures A callback accepting an AMQPChannel as a parameter.
     */
    public function execute(callable $rabbitMqProcedures): void
    {
        /** @var AMQPChannel */
        $channel = $this->channel();

        /** @var int */
        $channelId = $channel->getChannelId();

        /** @var int */
        $attempts = 1;

        /**
         * Consecutive timeouts that happened during an operation.
         * Limits of 3 timeout will cause a graceful end.
         */
        $consecutiveTimeouts = 0;

        /**
         * @var bool
         */
        $isTimeoutException = false;

        /**
         * Handles the living state of this current listener. Any blocking or serious
         * error will result in a graceful exit. It is used also to avoid channel
         * leak, as we count on a finally to handle any post-processing error and
         * free memory.
         * @var bool
         */
        $shouldExit = false;

        do {
            try {
                $rabbitMqProcedures($channel);
                $shouldExit = true;
            } catch (AMQPTimeoutException $e) {
                $this->logger->debug($e->getMessage());

                $this->logger->warning(
                    'Timeout during RabbitMQ operation. Waiting 2s before retrying.'
                );

                $isTimeoutException = true;
                $consecutiveTimeouts++;

                // Completly synthetic. Maybe some pause will help?
                sleep($consecutiveTimeouts);
            } catch (AMQPConnectionClosedException $e) {
                $this->logger->debug($e->getMessage());
                $this->logger->debug('Reconnecting to RabbitMQ...');

                $this->connect();
            } catch (AMQPChannelClosedException $e) {
                $this->logger->debug($e->getMessage());
                $this->logger->debug('Opening channel...');

                $channel = $this->channel($channelId);
                $channelId = $channel->getChannelId();
            } catch (\Throwable $e) {
                // We want to avoid channel leak.
                // Thus, we listen for any throwable, to avoid leak caused
                // by potential uncaught errors.
                $shouldExit = true;

                throw new RabbitMqException($e->getMessage());
            } finally {
                if ($consecutiveTimeouts >= self::MAX_CONSECUTIVE_TIMEOUTS) {
                    $this->logger->warning(
                        self::MAX_CONSECUTIVE_TIMEOUTS .
                            ' consecutive timeout limit reached. Ending listener.'
                    );
                    $shouldExit = true;
                }

                if (!$this->isConnected()) {
                    $this->logger->warning(
                        'RabbitMQ connection closed. Ending listener.'
                    );
                    $shouldExit = true;
                }
            }

            $attempts++;

            if ($isTimeoutException) {
                $isTimeoutException = false;
            } else {
                $consecutiveTimeouts = 0;
            }
        } while (!$shouldExit && $attempts <= self::MAX_PROCEDURES_ATTEMPTS);

        if ($channel && $channel->is_open()) {
            try {
                $channel->close();
            } catch (\Exception) {
            }
        }
    }
}
