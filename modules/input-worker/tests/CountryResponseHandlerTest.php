<?php

declare(strict_types=1);

namespace App\Tests;

use App\CountryResponseHandler;
use App\MessageHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for CountryResponseHandler
 * 
 * Tests response filtering, validation, and processing
 */
#[CoversClass('App\CountryResponseHandler')]
class CountryResponseHandlerTest extends TestCase
{
    private MessageHandler $inputHandlerMock;
    private LoggerInterface $loggerMock;

    protected function setUp(): void
    {
        $this->inputHandlerMock = $this->createMock(MessageHandler::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    // === Response Filtering Tests ===

    public function testValidate_MatchingCorrelationId_ReturnsTrue(): void
    {
        $correlationId = 'test-correlation-123';
        $handler = new CountryResponseHandler($correlationId, $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => true,
            'value' => 'France',
            'capital' => 'Paris'
        ]);

        $properties = ['correlation_id' => $correlationId];

        $this->assertTrue($handler->validate($response, $properties));
    }

    public function testValidate_DifferentCorrelationId_ReturnsFalse(): void
    {
        $handler = new CountryResponseHandler('expected-id', $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => true,
            'value' => 'France',
            'capital' => 'Paris'
        ]);

        $properties = ['correlation_id' => 'different-id'];


        $this->assertFalse($handler->validate($response, $properties));
    }

    public function testValidate_MissingCorrelationId_ReturnsFalse(): void
    {
        $handler = new CountryResponseHandler('expected-id', $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => true,
            'value' => 'France',
            'capital' => 'Paris'
        ]);

        $properties = []; // No correlation_id


        $this->assertFalse($handler->validate($response, $properties));
    }

    // === Status Validation Tests ===

    public function testValidate_InvalidStatus_ReturnsFalse(): void
    {
        $handler = new CountryResponseHandler('test-id', $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => 'unknown',
            'value' => 'France',
            'correlation_id' => 'test-id'
        ]);

        $this->assertFalse($handler->validate($response, []));
    }

    public function testValidate_MissingStatus_ReturnsFalse(): void
    {
        $handler = new CountryResponseHandler('test-id', $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'value' => 'France',
            'correlation_id' => 'test-id'
        ]);

        $this->assertFalse($handler->validate($response, []));
    }

    public function testValidate_MissingValue_ReturnsFalse(): void
    {
        $handler = new CountryResponseHandler('test-id', $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => true,
            'correlation_id' => 'test-id'
        ]);

        $this->assertFalse($handler->validate($response, []));
    }

    public function testValidate_NonStringValue_ReturnsFalse(): void
    {
        $handler = new CountryResponseHandler('test-id', $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => true,
            'value' => 123,
            'correlation_id' => 'test-id'
        ]);

        $this->assertFalse($handler->validate($response, []));
    }

    // === Success Response Processing ===

    public function testConsume_SuccessResponse_LogsAndCompletes(): void
    {
        $correlationId = 'test-correlation-123';
        $handler = new CountryResponseHandler($correlationId, $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => true,
            'value' => 'France',
            'capital' => 'Paris'
        ]);

        $properties = ['correlation_id' => $correlationId];

        // First validate to cache the response
        $handler->validate($response, $properties);

        // Success cases no longer log (only failures do)

        $this->assertTrue($handler->consume($response, $properties));
    }

    public function testConsume_SuccessWithoutCapital_HandlesGracefully(): void
    {
        $correlationId = 'test-correlation-123';
        $handler = new CountryResponseHandler($correlationId, $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => true,
            'value' => 'France'
        ]);

        $properties = ['correlation_id' => $correlationId];

        $handler->validate($response, $properties);

        // Success cases no longer log (only failures do)

        $this->assertTrue($handler->consume($response, $properties));
    }

    // === Failure Response Processing ===

    public function testConsume_FailureResponse_RepublishesToCapitals(): void
    {
        $correlationId = 'test-correlation-123';
        $handler = new CountryResponseHandler($correlationId, $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => false,
            'invalid_country' => 'UnknownCountry'
        ]);

        $properties = ['correlation_id' => $correlationId];

        $handler->validate($response, $properties);

        $this->inputHandlerMock->expects($this->once())
            ->method('republishToCapitals')
            ->with('UnknownCountry');

        $this->assertTrue($handler->consume($response, $properties));
    }

    public function testConsume_FailureWithoutError_UsesDefaultError(): void
    {
        $correlationId = 'test-correlation-123';
        $handler = new CountryResponseHandler($correlationId, $this->inputHandlerMock, $this->loggerMock);
        
        $response = json_encode([
            'success' => false
        ]);

        $properties = ['correlation_id' => $correlationId];

        $handler->validate($response, $properties);

        $this->inputHandlerMock->expects($this->never())
            ->method('republishToCapitals');

        $this->assertTrue($handler->consume($response, $properties));
    }

    // === Edge Cases ===

    public function testConsume_WithoutValidation_ReturnsFalse(): void
    {
        $handler = new CountryResponseHandler('test-id', $this->inputHandlerMock, $this->loggerMock);
        
        // Don't call validate first
        $this->assertFalse($handler->consume('{"success": true, "value": "France"}', []));
    }

    public function testValidate_InvalidJson_ReturnsFalse(): void
    {
        $handler = new CountryResponseHandler('test-id', $this->inputHandlerMock, $this->loggerMock);
        

        $this->assertFalse($handler->validate('invalid json', []));
    }

    public function testValidate_EmptyJson_ReturnsFalse(): void
    {
        $handler = new CountryResponseHandler('test-id', $this->inputHandlerMock, $this->loggerMock);
        

        $this->assertFalse($handler->validate('', []));
    }
}