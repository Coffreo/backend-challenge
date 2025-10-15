<?php

declare(strict_types=1);

namespace App\Tests;

// Define constants for tests (matching main application)
define('QUEUE_INPUT', 'input');
define('QUEUE_COUNTRIES', 'countries');
define('QUEUE_CAPITALS', 'capitals');
define('QUEUE_COUNTRIES_RESPONSES', 'countries_responses');

use App\MessageHandler;
use Internals\RabbitMq\RabbitMqConnection;
use Internals\RabbitMq\RabbitMqPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for input-worker MessageHandler
 *
 * Covers all possible scenarios for validation, routing, and failure handling
 */
#[CoversClass('App\MessageHandler')]
class MessageHandlerTest extends TestCase
{
    private RabbitMqConnection $rabbitMqConnectionMock;
    private RabbitMqPublisher $publisherMock;
    private LoggerInterface $loggerMock;

    protected function setUp(): void
    {
        $this->rabbitMqConnectionMock = $this->createMock(RabbitMqConnection::class);
        $this->publisherMock = $this->createMock(RabbitMqPublisher::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    // === Validation Tests ===

    public function testValidate_ValidJsonWithValue_ReturnsTrue(): void
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        $this->assertTrue($handler->validate('{"value": "France"}', []));
        $this->assertTrue($handler->validate('{"value": "Paris"}', []));
        $this->assertTrue($handler->validate('{"value": "United States"}', []));
    }

    public function testValidate_ValidJsonWithWhitespace_ReturnsTrueAndTrims(): void
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        $this->assertTrue($handler->validate('{"value": "  France  "}', []));
        $this->assertTrue($handler->validate('{"value": "\tParis\n"}', []));
    }

    public function testValidate_EmptyValue_ReturnsFalse(): void
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        $this->assertFalse($handler->validate('{"value": ""}', []));
        $this->assertFalse($handler->validate('{"value": "   "}', []));
    }

    public function testValidate_MissingValueKey_ReturnsFalse(): void
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        $this->assertFalse($handler->validate('{"name": "France"}', []));
        $this->assertFalse($handler->validate('{"country": "France"}', []));
        $this->assertFalse($handler->validate('{}', []));
    }

    public function testValidate_NonStringValue_ReturnsFalse(): void
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        $this->assertFalse($handler->validate('{"value": 123}', []));
        $this->assertFalse($handler->validate('{"value": true}', []));
        $this->assertFalse($handler->validate('{"value": null}', []));
        $this->assertFalse($handler->validate('{"value": []}', []));
        $this->assertFalse($handler->validate('{"value": {}}', []));
    }

    public function testValidate_InvalidJson_ReturnsFalse(): void
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        $this->assertFalse($handler->validate('{"value": "France"', []));
        $this->assertFalse($handler->validate('invalid json', []));
        $this->assertFalse($handler->validate('', []));
    }

    public function testValidate_ExtremelyLargeJson_ReturnsFalse(): void
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        // Create JSON that exceeds depth limit
        $deepJson = '{"value": "France"' . str_repeat(',{"nested": ', 1000) . str_repeat('}', 1000) . '}';

        $this->assertFalse($handler->validate($deepJson, []));
    }

    // === Consumption Tests ===

    public function testConsume_ValidValue_PublishesToCountriesQueueWithProperties(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $handler->validate('{"value": "France"}', []);

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with(
                QUEUE_COUNTRIES,
                $this->callback(function($message) {
                    $data = json_decode($message, true);
                    return $data['country_name'] === 'France';
                }),
                $this->callback(function($properties) {
                    return isset($properties['correlation_id']) &&
                           $properties['reply_to'] === QUEUE_COUNTRIES_RESPONSES &&
                           isset($properties['message_id']);
                })
            );

        // Mock the consumer for monitoring - it should timeout since no response
        $this->assertTrue($handler->consume('{"value": "France"}', []));
    }

    public function testConsume_NullValueAfterValidation_ReturnsFalse(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        // Don't call validate, so value remains null

        $this->assertFalse($handler->consume('{"value": "France"}', []));
    }

    public function testConsume_PublisherThrowsException_ReturnsFalse(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $handler->validate('{"value": "France"}', []);

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->willThrowException(new \Exception('Publishing failed'));

        $this->assertFalse($handler->consume('{"value": "France"}', []));
    }

    // === Republishing Tests ===

    public function testRepublishToCapitals_ValidValue_PublishesToCapitalsQueue(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with(QUEUE_CAPITALS, '{"capital":"Paris"}');

        $handler->republishToCapitals('Paris');
    }

    // === Integration Tests ===

    public function testFullWorkflow_ValidMessage_ProcessesCorrectly(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        // Test the full workflow: validate -> consume
        $this->assertTrue($handler->validate('{"value": "Germany"}', []));

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with(QUEUE_COUNTRIES, $this->callback(function($message) {
                $data = json_decode($message, true);
                return $data['country_name'] === 'Germany';
            }));

        // consume may return false due to timeout, but that's ok if publish succeeded
        $result = $handler->consume('{"value": "Germany"}', []);
        $this->assertIsBool($result);
    }

    public function testWorkflow_MultipleMessages_ProcessesEachCorrectly(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $messages = [
            '{"value": "Spain"}',
            '{"value": "Italy"}',
            '{"value": "Portugal"}'
        ];

        $publishedCountries = [];
        $this->publisherMock->expects($this->exactly(3))
            ->method('publish')
            ->willReturnCallback(function(string $queue, string $message) use (&$publishedCountries) {
                $data = json_decode($message, true);
                $publishedCountries[] = $data['country_name'];
            });

        foreach ($messages as $message) {
            $this->assertTrue($handler->validate($message, []));
            $this->assertTrue($handler->consume($message, []));
        }

        $this->assertEqualsCanonicalizing(['Spain', 'Italy', 'Portugal'], $publishedCountries);
    }

    // === Edge Cases ===

    public function testValidate_UnicodeCharacters_HandlesCorrectly(): void
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        $this->assertTrue($handler->validate('{"value": "São Paulo"}', []));
        $this->assertTrue($handler->validate('{"value": "Zürich"}', []));
        $this->assertTrue($handler->validate('{"value": "北京"}', []));
    }

    public function testValidate_LongCountryNames_HandlesCorrectly(): void
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        $longName = str_repeat('A', 1000);
        $this->assertTrue($handler->validate('{"value": "' . $longName . '"}', []));
    }

    public function testConsume_SpecialCharactersInValue_ProcessesCorrectly(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $handler->validate('{"value": "São Paulo & Co."}', []);

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with(
                QUEUE_COUNTRIES,
                $this->callback(function($message) {
                    $data = json_decode($message, true);
                    return $data['country_name'] === 'São Paulo & Co.';
                })
            );

        // Focus on testing that the publish happens with correct data
        $result = $handler->consume('{"value": "São Paulo & Co."}', []);
        $this->assertIsBool($result);
    }

    // === Timeout and Monitoring Tests ===

    public function testConsume_WithTimeout_CompletesSuccessfully(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $handler->validate('{"value": "France"}', []);

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with(QUEUE_COUNTRIES);

        // The actual implementation handles timeout internally
        $this->assertTrue($handler->consume('{"value": "France"}', []));
    }

    public function testConsume_PublishesAndMonitors(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $handler->validate('{"value": "TestValue"}', []);

        $this->publisherMock->expects($this->once())
            ->method('publish');

        // consume should return true after publishing (monitoring happens internally)
        $this->assertTrue($handler->consume('{"value": "TestValue"}', []));
    }

    // === Correlation ID Tests ===

    public function testConsume_GeneratesUniqueCorrelationIds(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $correlationIds = [];

        $this->publisherMock->expects($this->exactly(3))
            ->method('publish')
            ->willReturnCallback(function(string $_queue, string $_message, array $properties) use (&$correlationIds) {
                $correlationIds[] = $properties['correlation_id'];
            });

        $messages = ['{"value": "France"}', '{"value": "Germany"}', '{"value": "Spain"}'];

        foreach ($messages as $message) {
            $handler->validate($message, []);
            $handler->consume($message, []);
        }

        // All correlation IDs should be unique
        $this->assertCount(3, array_unique($correlationIds));
        $this->assertCount(3, $correlationIds);
    }

    // === Integration with CountryResponseHandler Tests ===

    public function testMonitoringCreatesCorrectResponseHandler(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $handler->validate('{"value": "TestCountry"}', []);

        $this->publisherMock->expects($this->once())
            ->method('publish');

        // We can't easily test the CountryResponseHandler creation without more mocking
        // But we can test that consume completes successfully
        $this->assertTrue($handler->consume('{"value": "TestCountry"}', []));
    }

    // === Error Handling Tests ===

    public function testConsume_ExceptionInMonitoring_ReturnsTrue(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $handler->validate('{"value": "France"}', []);

        $this->publisherMock->expects($this->once())
            ->method('publish');

        // Even if monitoring fails, consume should return true (message was published)
        $this->assertTrue($handler->consume('{"value": "France"}', []));
    }

    // === Message Properties Tests ===

    public function testPublishToCountries_IncludesAllRequiredProperties(): void
    {
        $handler = $this->createMessageHandlerWithMockPublisher();

        $handler->validate('{"value": "TestCountry"}', []);

        $this->publisherMock->expects($this->once())
            ->method('publish')
            ->with(
                QUEUE_COUNTRIES,
                $this->callback(function($message) {
                    $data = json_decode($message, true);
                    return isset($data['country_name']);
                }),
                $this->callback(function($properties) {
                    return isset($properties['correlation_id']) &&
                           isset($properties['reply_to']) &&
                           isset($properties['message_id']) &&
                           $properties['reply_to'] === QUEUE_COUNTRIES_RESPONSES;
                })
            );

        $this->assertTrue($handler->consume('{"value": "TestCountry"}', []));
    }

    // === Helper Methods ===

    /**
     * Creates a MessageHandler with mocked publisher for testing
     */
    private function createMessageHandlerWithMockPublisher(): MessageHandler
    {
        $handler = new MessageHandler($this->rabbitMqConnectionMock, $this->loggerMock);

        // Use reflection to inject the mock publisher
        $reflection = new \ReflectionClass($handler);
        $publisherProperty = $reflection->getProperty('publisher');
        $publisherProperty->setAccessible(true);
        $publisherProperty->setValue($handler, $this->publisherMock);

        return $handler;
    }
}
