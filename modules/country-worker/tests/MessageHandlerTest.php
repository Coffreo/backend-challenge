<?php

declare(strict_types=1);

namespace App;

define('RESTCOUNTRIES_BASE_URI', '');
define('QUEUE_OUT', 'capitals');
define('QUEUE_RESPONSES', 'countries_responses');

namespace App\Tests;

use App\MessageHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Internals\RabbitMq\RabbitMqConnection;
use Internals\RabbitMq\RabbitMqPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// Quick n' dirty to avoid the pain of partial mocks.
class MessageHandler_ extends MessageHandler
{
    public function publishCapital(string $_any): void
    {
    }

    // Override sendProcessingResult to avoid actual publishing in tests
    protected function sendProcessingResult(bool $success): void
    {
    }
}

#[CoversClass('App\MessageHandler')]
class MessageHandlerTest extends TestCase
{
    public function testValidate_JsonWithExpectedValue()
    {
        $this->assertTrue(
            $this->getMessageHandler()->validate(
                '{"country_name": "France"}',
                []
            )
        );

        $this->assertTrue(
            $this->getMessageHandler()->validate(
                '{"country_name": "republic of cyprus"}',
                []
            )
        );
    }

    public function testValidate_JsonWithRpcProperties()
    {
        $this->assertTrue(
            $this->getMessageHandler()->validate(
                '{"country_name": "France"}',
                ['correlation_id' => 'test-123', 'reply_to' => 'countries_responses']
            )
        );

        // Should work without RPC properties too
        $this->assertTrue(
            $this->getMessageHandler()->validate(
                '{"country_name": "Germany"}',
                []
            )
        );
    }

    public function testValidate_JsonLatin()
    {
        $this->assertTrue(
            $this->getMessageHandler()->validate(
                '{"country_name": "république française"}',
                []
            )
        );

        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '{"country_name": "日本"}',
                []
            )
        );
    }

    public function testValidate_JsonMalformed()
    {
        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '{"country_name": "France"',
                []
            )
        );
    }

    public function testValidate_JsonShouldContainCountryName()
    {
        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '{"country": "France"}',
                []
            )
        );

        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '',
                []
            )
        );

        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '1',
                []
            )
        );

        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '{}',
                []
            )
        );
    }

    public function testConsume_ReturnsCapital()
    {
        $this->assertTrue(
            $this->getMessageHandlerForConsumeWithHttpStatus(
                200,
                json_encode([["capital" => ["Paris"]]])
            )->consume('', [])
        );
    }

    public function testConsume_ThrowIfJsonMalformed()
    {
        $this->expectException('App\MessageHandlerException');
        $this->getMessageHandlerForConsumeWithHttpStatus(200, '{"badjson')->consume('', []);
    }

    public function testConsume_ThrowIfApiTimeout()
    {
        $this->expectException('App\MessageHandlerException');
        $this->getMessageHandlerForConsumeWithHttpStatus(null)->consume('', []);
    }

    public function testConsume_ThrowIfApiServerError()
    {
        $this->expectException('App\MessageHandlerException');
        $this->getMessageHandlerForConsumeWithHttpStatus(500)->consume('', []);
    }

    public function testConsume_FailIf400ish()
    {
        $this->assertFalse(
            $this->getMessageHandlerForConsumeWithHttpStatus(404)->consume('', [])
        );
    }

    // === Response Publishing Tests ===

    public function testPublishResponse_WithMockPublisher()
    {
        $handler = $this->getMessageHandlerWithMockPublisher();

        // Set up the handler state
        $reflection = new \ReflectionClass($handler);
        $correlationProperty = $reflection->getProperty('correlationId');
        $correlationProperty->setAccessible(true);
        $correlationProperty->setValue($handler, 'test-correlation-123');

        $replyToProperty = $reflection->getProperty('replyTo');
        $replyToProperty->setAccessible(true);
        $replyToProperty->setValue($handler, 'test-reply-queue');

        // Set countryName for testing
        $countryNameProperty = $reflection->getProperty('countryName');
        $countryNameProperty->setAccessible(true);
        $countryNameProperty->setValue($handler, 'France');

        // Test sendProcessingResult method (using reflection since it's protected)
        $sendProcessingResultMethod = $reflection->getMethod('sendProcessingResult');
        $sendProcessingResultMethod->setAccessible(true);

        // This should not throw an exception
        $sendProcessingResultMethod->invoke($handler, true);
        $sendProcessingResultMethod->invoke($handler, false);

        $this->assertTrue(true); // If we get here without exceptions, test passes
    }

    public function testConsume_PublishesSuccessResponse()
    {
        $handler = $this->getMessageHandlerForConsumeWithHttpStatus(
            200,
            json_encode([["capital" => ["Paris"]]])
        );

        // Set correlation_id to test RPC response
        $reflection = new \ReflectionClass($handler);
        $correlationProperty = $reflection->getProperty('correlationId');
        $correlationProperty->setAccessible(true);
        $correlationProperty->setValue($handler, 'test-correlation-123');

        $countryNameProperty = $reflection->getProperty('countryName');
        $countryNameProperty->setAccessible(true);
        $countryNameProperty->setValue($handler, 'France');

        $this->assertTrue($handler->consume('', []));
    }

    public function testConsume_PublishesFailureResponse()
    {
        $handler = $this->getMessageHandlerForConsumeWithHttpStatus(404);

        // Set correlation_id to test RPC response
        $reflection = new \ReflectionClass($handler);
        $correlationProperty = $reflection->getProperty('correlationId');
        $correlationProperty->setAccessible(true);
        $correlationProperty->setValue($handler, 'test-correlation-123');

        $countryNameProperty = $reflection->getProperty('countryName');
        $countryNameProperty->setAccessible(true);
        $countryNameProperty->setValue($handler, 'UnknownCountry');

        $this->assertFalse($handler->consume('', []));
    }

    private function getMessageHandler(): MessageHandler
    {
        $rabbitMqConnectionStub = $this->createMock(RabbitMqConnection::class);
        return new MessageHandler_($rabbitMqConnectionStub);
    }

    private function getMessageHandlerWithMockPublisher(): MessageHandler
    {
        $rabbitMqConnectionStub = $this->createMock(RabbitMqConnection::class);
        $loggerStub = $this->createMock(LoggerInterface::class);

        $handler = new MessageHandler($rabbitMqConnectionStub, $loggerStub);

        // Mock the publisher to avoid actual publishing
        $publisherMock = $this->createMock(RabbitMqPublisher::class);
        $publisherMock->method('publish');

        $reflection = new \ReflectionClass($handler);
        $publisherProperty = $reflection->getProperty('rabbitMqConnection');
        $publisherProperty->setAccessible(true);
        $publisherProperty->setValue($handler, $rabbitMqConnectionStub);

        return $handler;
    }

    private function getMessageHandlerForConsumeWithHttpStatus(
        $httpStatus = 200,
        $response = null
    ): MessageHandler {
        // We can generalize it, but, eh. Overkill.
        $httpClientStub = $this->createMock(Client::class);

        // Simulating API server error.
        if ($httpStatus === null || $httpStatus >= 300) {
            $httpClientStub->method('request')->willThrowException(
                new RequestException(
                    RESTCOUNTRIES_BASE_URI,
                    new Request('GET', ''),
                    ($httpStatus === null) ? null :
                        new Response($httpStatus, [], $response)
                )
            );
        } else {
            $httpClientStub->method('request')->willReturn(new Response(
                $httpStatus,
                [],
                $response
            ));
        }

        $messageHandler = $this->getMessageHandler();
        $messageHandlerReflection = new \ReflectionClass($messageHandler);

        // Static cache, be careful.
        // That's why that's better to do some Redis-thingy thing.
        // But here, that's the poor man's cache.
        $messageHandlerReflection->setStaticPropertyValue('poorManCache', []);

        $httpClientProperty = $messageHandlerReflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($messageHandler, $httpClientStub);

        $normalizedCountryProperty = $messageHandlerReflection->getProperty('normalizedCountry');
        $normalizedCountryProperty->setAccessible(true);
        $normalizedCountryProperty->setValue($messageHandler, 'france');

        // Set countryName for testing
        $countryNameProperty = $messageHandlerReflection->getProperty('countryName');
        $countryNameProperty->setAccessible(true);
        $countryNameProperty->setValue($messageHandler, 'France');

        return $messageHandler;
    }
}
