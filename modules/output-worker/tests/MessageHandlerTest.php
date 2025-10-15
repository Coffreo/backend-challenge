<?php

declare(strict_types=1);

namespace App\Tests;

use App\MessageHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MessageHandlerTest extends TestCase
{
    private MessageHandler $messageHandler;

    protected function setUp(): void
    {
        $logger = new NullLogger();
        $this->messageHandler = new MessageHandler($logger);
    }

    public function testValidateValidMessage(): void
    {
        $validMessage = json_encode([
            'capital' => 'Paris',
            'country' => 'France',
            'weather' => [
                'city' => 'Paris',
                'temperature' => 20,
                'condition' => 'sunny',
                'humidity' => 60,
                'wind_speed' => 10,
                'timestamp' => '2024-01-15T10:30:00Z'
            ]
        ]);

        $result = $this->messageHandler->validate($validMessage, []);
        $this->assertTrue($result);
    }

    public function testValidateMessageMissingCapital(): void
    {
        $invalidMessage = json_encode([
            'country' => 'France',
            'weather' => [
                'city' => 'Paris',
                'temperature' => 20,
                'condition' => 'sunny'
            ]
        ]);

        $result = $this->messageHandler->validate($invalidMessage, []);
        $this->assertFalse($result);
    }

    public function testValidateMessageMissingCountry(): void
    {
        $invalidMessage = json_encode([
            'capital' => 'Paris',
            'weather' => [
                'city' => 'Paris',
                'temperature' => 20,
                'condition' => 'sunny'
            ]
        ]);

        $result = $this->messageHandler->validate($invalidMessage, []);
        $this->assertFalse($result);
    }

    public function testValidateMessageMissingWeather(): void
    {
        $invalidMessage = json_encode([
            'capital' => 'Paris',
            'country' => 'France'
        ]);

        $result = $this->messageHandler->validate($invalidMessage, []);
        $this->assertFalse($result);
    }

    public function testValidateInvalidJson(): void
    {
        $invalidJson = 'invalid json';

        $result = $this->messageHandler->validate($invalidJson, []);
        $this->assertFalse($result);
    }

    public function testConsumeValidMessage(): void
    {
        $validMessage = json_encode([
            'capital' => 'Paris',
            'country' => 'France',
            'weather' => [
                'city' => 'Paris',
                'temperature' => 20,
                'condition' => 'sunny',
                'humidity' => 60,
                'wind_speed' => 10,
                'timestamp' => '2024-01-15T10:30:00Z'
            ]
        ]);

        $result = $this->messageHandler->consume($validMessage, []);
        $this->assertTrue($result);
    }

    public function testConsumeInvalidJson(): void
    {
        $invalidJson = 'invalid json';

        $result = $this->messageHandler->consume($invalidJson, []);
        $this->assertFalse($result);
    }
}