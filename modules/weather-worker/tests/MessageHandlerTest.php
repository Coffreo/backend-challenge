<?php

declare(strict_types=1);

namespace App\Tests;

use App\MessageHandler;
use Internals\RabbitMq\RabbitMqConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MessageHandlerTest extends TestCase
{
    private MessageHandler $messageHandler;

    protected function setUp(): void
    {
        // Define the constant for tests
        if (!defined('API_WEATHER_BASE_URI')) {
            define('API_WEATHER_BASE_URI', 'http://test-api/');
        }
        
        $rabbitMqConnection = $this->createMock(RabbitMqConnection::class);
        $logger = new NullLogger();
        $this->messageHandler = new MessageHandler($rabbitMqConnection, $logger);
    }

    public function testValidateValidMessage(): void
    {
        $validMessage = json_encode([
            'capital' => 'Paris',
            'country' => 'France'
        ]);

        $result = $this->messageHandler->validate($validMessage, []);
        $this->assertTrue($result);
    }

    public function testValidateInvalidMessage(): void
    {
        $invalidMessage = json_encode(['invalid' => 'data']);

        $result = $this->messageHandler->validate($invalidMessage, []);
        $this->assertFalse($result);
    }

    public function testValidateInvalidJson(): void
    {
        $invalidJson = 'invalid json';

        $result = $this->messageHandler->validate($invalidJson, []);
        $this->assertFalse($result);
    }

    public function testValidateMessageWithMissingCapital(): void
    {
        $messageWithoutCapital = json_encode([
            'country' => 'France'
        ]);

        $result = $this->messageHandler->validate($messageWithoutCapital, []);
        $this->assertFalse($result);
    }

    public function testValidateMessageWithMissingCountry(): void
    {
        $messageWithoutCountry = json_encode([
            'capital' => 'Paris'
        ]);

        $result = $this->messageHandler->validate($messageWithoutCountry, []);
        $this->assertTrue($result); // Should still be valid, country is optional
    }

    public function testValidateEmptyCapital(): void
    {
        $messageWithEmptyCapital = json_encode([
            'capital' => '',
            'country' => 'France'
        ]);

        $result = $this->messageHandler->validate($messageWithEmptyCapital, []);
        $this->assertFalse($result);
    }
}