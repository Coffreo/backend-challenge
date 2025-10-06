<?php

declare(strict_types=1);

namespace Internals\RabbitMq\Tests;

use Internals\RabbitMq\RabbitMqConnection;
use Internals\RabbitMq\RabbitMqException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;


#[CoversClass('Internals\RabbitMq\RabbitMqConnection')]
class RabbitMqConnectionTest extends TestCase
{
    public function testIsConnected()
    {
        $channelStub = $this->createMock(AMQPChannel::class);
        $channelStub->method('is_open')->willReturn(true);

        $amqpStreamConnectionStub = $this->createMock(AMQPStreamConnection::class);
        $amqpStreamConnectionStub->method('isConnected')->willReturn(true);
        $amqpStreamConnectionStub->method('channel')->willReturn($channelStub);

        $rabbitMqConnection = $this->getRabbitMqConnection($amqpStreamConnectionStub);

        $this->assertTrue($rabbitMqConnection->isConnected());
    }

    public function testChannel_canOpen()
    {
        $channelStub = $this->createMock(AMQPChannel::class);
        $channelStub->method('is_open')->willReturn(true);

        $amqpStreamConnectionStub = $this->createMock(AMQPStreamConnection::class);
        $amqpStreamConnectionStub->method('isConnected')->willReturn(true);
        $amqpStreamConnectionStub->method('channel')->willReturn($channelStub);

        $rabbitMqConnection = $this->getRabbitMqConnection($amqpStreamConnectionStub);

        $this->assertInstanceOf(AMQPChannel::class, $rabbitMqConnection->channel());
    }

    public function testChannel_throwOnMultipleFails()
    {
        $this->expectException(RabbitMqException::class);

        $amqpStreamConnectionStub = $this->createMock(AMQPStreamConnection::class);
        $amqpStreamConnectionStub->method('isConnected')->willReturn(true);
        $amqpStreamConnectionStub->method('channel')->willThrowException(
            new AMQPConnectionClosedException()
        );

        $rabbitMqConnection = $this->getRabbitMqConnection($amqpStreamConnectionStub);

        $this->assertInstanceOf(AMQPChannel::class, $rabbitMqConnection->channel());
    }

    public function testExecute_canExecute()
    {
        $this->expectNotToPerformAssertions();

        $channelStub = $this->createMock(AMQPChannel::class);
        $channelStub->method('is_open')->willReturn(true);
        $channelStub->method('getChannelId')->willReturn(1);

        $amqpStreamConnectionStub = $this->createMock(AMQPStreamConnection::class);
        $amqpStreamConnectionStub->method('isConnected')->willReturn(true);
        $amqpStreamConnectionStub->method('channel')->willReturn($channelStub);

        $rabbitMqConnection = $this->getRabbitMqConnection($amqpStreamConnectionStub);

        $rabbitMqConnection->execute(function (AMQPChannel $_) {
            // Nothin'
        });
    }

    public function testExecute_reopenConnectionOnFail()
    {
        $channelStub = $this->createMock(AMQPChannel::class);
        $channelStub->method('is_open')->willReturn(true);
        $channelStub->method('getChannelId')->willReturn(1);

        $amqpStreamConnectionStub = $this->createMock(AMQPStreamConnection::class);
        $amqpStreamConnectionStub->method('isConnected')->willReturn(true);
        $amqpStreamConnectionStub->method('channel')->willReturn($channelStub);

        $rabbitMqConnection = $this->getRabbitMqConnection($amqpStreamConnectionStub);

        $count = 0;

        $rabbitMqConnection->execute(function (AMQPChannel $_) use (&$count) {
            if ($count++ === 0) {
                throw new AMQPConnectionClosedException();
            }
        });

        $this->assertEquals(2, $count);
    }

    public function testExecute_endGracefullyOnUnrecoverableError()
    {
        $channelStub = $this->createMock(AMQPChannel::class);
        $channelStub->method('is_open')->willReturn(true);
        $channelStub->method('getChannelId')->willReturn(1);

        $amqpStreamConnectionStub = $this->createMock(AMQPStreamConnection::class);
        $amqpStreamConnectionStub->method('isConnected')->willReturn(true);
        $amqpStreamConnectionStub->method('channel')->willReturn($channelStub);

        $rabbitMqConnection = $this->getRabbitMqConnection($amqpStreamConnectionStub);

        $count = 0;

        $rabbitMqConnection->execute(function (AMQPChannel $_) use (&$count) {
            $count++;
            throw new AMQPConnectionClosedException();
        });

        $this->assertEquals(RabbitMqConnection::MAX_PROCEDURES_ATTEMPTS, $count);
    }

    public function testExecute_rethrowErrors()
    {
        $this->expectException(RabbitMqException::class);

        $channelStub = $this->createMock(AMQPChannel::class);
        $channelStub->method('is_open')->willReturn(true);
        $channelStub->method('getChannelId')->willReturn(1);

        $amqpStreamConnectionStub = $this->createMock(AMQPStreamConnection::class);
        $amqpStreamConnectionStub->method('isConnected')->willReturn(true);
        $amqpStreamConnectionStub->method('channel')->willReturn($channelStub);

        $rabbitMqConnection = $this->getRabbitMqConnection($amqpStreamConnectionStub);

        $rabbitMqConnection->execute(function (AMQPChannel $_) {
            throw new \Error();
        });
    }

    private function getRabbitMqConnection(MockObject $amqpStreamConnectionStub): RabbitMqConnection
    {
        $rabbitMqConnection = new RabbitMqConnection();
        $rabbitMqConnectionReflection = new \ReflectionClass($rabbitMqConnection);

        $amqpStreamConnectionProperty = $rabbitMqConnectionReflection->getProperty('amqpConnection');
        $amqpStreamConnectionProperty->setAccessible(true);
        $amqpStreamConnectionProperty->setValue($rabbitMqConnection, $amqpStreamConnectionStub);

        return $rabbitMqConnection;
    }
}
