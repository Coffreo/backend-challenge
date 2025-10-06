<?php

declare(strict_types=1);

namespace App;

define('RESTCOUNTRIES_BASE_URI', '');

namespace App\Tests;

use App\MessageHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Internals\RabbitMq\RabbitMqConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

// Quick n' dirty to avoid the pain of partial mocks.
class MessageHandler_ extends MessageHandler
{
    public function publish(string $_any): void
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
                '{"country_name": "France"}'
            )
        );

        $this->assertTrue(
            $this->getMessageHandler()->validate(
                '{"country_name": "republic of cyprus"}'
            )
        );
    }

    public function testValidate_JsonLatin()
    {
        $this->assertTrue(
            $this->getMessageHandler()->validate(
                '{"country_name": "république française"}'
            )
        );

        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '{"country_name": "日本"}'
            )
        );
    }

    public function testValidate_JsonMalformed()
    {
        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '{"country_name": "France"'
            )
        );
    }

    public function testValidate_JsonShouldContainCountryName()
    {
        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '{"country": "France"}'
            )
        );

        $this->assertFalse(
            $this->getMessageHandler()->validate(
                ''
            )
        );

        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '1'
            )
        );

        $this->assertFalse(
            $this->getMessageHandler()->validate(
                '{}'
            )
        );
    }

    public function testConsume_ReturnsCapital()
    {
        $this->assertTrue(
            $this->getMessageHandlerForConsumeWithHttpStatus(
                200,
                json_encode([["capital" => ["Paris"]]])
            )->consume('')
        );
    }

    public function testConsume_ThrowIfJsonMalformed()
    {
        $this->expectException('App\MessageHandlerException');
        $this->getMessageHandlerForConsumeWithHttpStatus(200, '{"badjson')->consume('');
    }

    public function testConsume_ThrowIfApiTimeout()
    {
        $this->expectException('App\MessageHandlerException');
        $this->getMessageHandlerForConsumeWithHttpStatus(null)->consume('');
    }

    public function testConsume_ThrowIfApiServerError()
    {
        $this->expectException('App\MessageHandlerException');
        $this->getMessageHandlerForConsumeWithHttpStatus(500)->consume('');
    }

    public function testConsume_FailIf400ish()
    {
        $this->assertFalse(
            $this->getMessageHandlerForConsumeWithHttpStatus(404)->consume('')
        );
    }

    private function getMessageHandler(): MessageHandler
    {
        $rabbitMqConnectionStub = $this->createMock(RabbitMqConnection::class);
        return new MessageHandler_($rabbitMqConnectionStub);
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

        $countryProperty = $messageHandlerReflection->getProperty('country');
        $countryProperty->setAccessible(true);
        $countryProperty->setValue($messageHandler, 'france');

        return $messageHandler;
    }
}
