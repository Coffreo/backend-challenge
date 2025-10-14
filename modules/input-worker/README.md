# Input Worker

The Input Worker serves as the intelligent entry point for the message processing pipeline, implementing an RPC-based routing strategy to handle both country names and capital city names.

## Overview

The Input Worker receives messages containing location values and intelligently routes them through the system:

1. **First attempt**: Routes the value as a country name to the `countries` queue
2. **Failure handling**: Monitors for failures from the country-worker
3. **Fallback**: If country lookup fails, republishes the value as a capital name to the `capitals` queue

## Architecture

### Message Flow

```
Input Message → Input Worker → Countries Queue → [Success/Failure]
                     ↓
            [On Failure] → Capitals Queue
```

### RPC Pattern Implementation

The worker implements an RPC-like pattern by:
- Publishing messages to the `countries` queue
- Listening for failure notifications on the `countries_failures` queue
- Automatically republishing failed messages to the `capitals` queue

## Message Formats

### Input Message Format

```json
{
  "value": "France"
}
```

or

```json
{
  "value": "Paris"
}
```

### Output Message Format (to countries queue)

```json
{
  "country_name": "France"
}
```

### Output Message Format (to capitals queue)

```json
{
  "capital_name": "Paris"
}
```

### Failure Message Format (from countries_failures queue)

```json
{
  "failed_value": "UnknownCountry"
}
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `RABBITMQ_QUEUE_COUNTRIES` | `countries` | Queue for country name messages |
| `RABBITMQ_QUEUE_CAPITALS` | `capitals` | Queue for capital name messages |
| `RABBITMQ_QUEUE_COUNTRIES_FAILURES` | `countries_failures` | Queue for failure notifications |

### Queue Configuration

- **Input Queue**: `input`
- **Output Queues**: 
  - `countries` (primary routing)
  - `capitals` (fallback routing)
- **Monitoring Queue**: `countries_failures`

## Classes

### MessageHandler

The main message handler implementing the `IRabbitMqMessageHandler` interface.

#### Key Methods

- `validate(string $body): bool` - Validates incoming message format
- `consume(string $payload): bool` - Processes and routes messages
- `republishToCapitals(string $value): void` - Handles fallback routing

#### Validation Rules

- Message must be valid JSON
- Must contain a `value` field
- Value must be a non-empty string
- Whitespace is trimmed automatically

### FailureHandler

Specialized handler for processing failure notifications from the country worker.

#### Key Methods

- `validate(string $body): bool` - Validates failure message format
- `consume(string $payload): bool` - Processes failure and triggers republish

#### Failure Processing

- Only processes failures matching the original value sent
- Triggers republishing to capitals queue
- Provides detailed logging for debugging

## Error Handling

### Validation Errors

- Invalid JSON format → Message rejected
- Missing `value` field → Message rejected
- Empty or non-string values → Message rejected

### Processing Errors

- Publisher failures → Message requeued for retry
- Connection errors → Handled by RabbitMQ connection layer
- Unexpected exceptions → Logged and message requeued

## Logging

The worker provides comprehensive logging for:

- Message validation results
- Routing decisions
- Failure notifications
- Republishing actions
- Error conditions

### Log Levels

- **INFO**: Normal operations, routing decisions
- **DEBUG**: Detailed message processing, queue operations
- **WARNING**: Validation failures, minor issues
- **ERROR**: Processing failures, connection issues

## Testing

### Unit Tests

Comprehensive unit tests cover:

#### MessageHandler Tests
- Valid/invalid message validation
- JSON parsing edge cases
- Routing logic
- Error handling
- Integration scenarios

#### FailureHandler Tests
- Failure message validation
- Value matching logic
- Republishing triggers
- Edge cases and complex scenarios

### Running Tests

```bash
cd modules/input-worker
composer install
vendor/bin/phpunit
```

### Test Coverage

Tests include coverage for:
- All validation scenarios
- Success and error paths
- Edge cases (Unicode, long strings, malformed JSON)
- Integration workflows
- Mock-based isolation testing

## Deployment

### Docker Configuration

The worker runs in a PHP 8.3 Alpine container with:
- Socket extensions for RabbitMQ connectivity
- Composer for dependency management
- Optimized autoloader for production

### Health Monitoring

- Container health checks (can be implemented)
- Comprehensive logging for operational monitoring
- Graceful error handling to prevent crashes

## Dependencies

### Required Packages

- `internals/rabbitmq`: RabbitMQ integration layer
- `monolog/monolog`: Structured logging
- `psr/log`: PSR-3 logging interface

### Development Dependencies

- `phpunit/phpunit`: Unit testing framework
- `phpstan/phpstan`: Static analysis (optional)

## Usage Examples

### Basic Message Processing

```php
// Input message
$input = '{"value": "France"}';

// Worker validates and routes to countries queue
$handler = new MessageHandler($connection, $logger);
if ($handler->validate($input)) {
    $handler->consume($input);
}
```

### Failure Handling

```php
// Failure notification triggers republish
$failureMessage = '{"failed_value": "UnknownCountry"}';

$failureHandler = new FailureHandler('UnknownCountry', $inputHandler, $logger);
if ($failureHandler->validate($failureMessage)) {
    $failureHandler->consume($failureMessage); // Triggers republish to capitals
}
```

## Best Practices

### Message Processing

1. **Always validate** messages before processing
2. **Handle failures gracefully** with appropriate logging
3. **Monitor queue health** to detect processing issues
4. **Use unique consumer IDs** for multiple worker instances

### Error Handling

1. **Log comprehensively** for debugging and monitoring
2. **Implement circuit breakers** for repeated failures
3. **Monitor failure rates** to detect systemic issues
4. **Provide clear error messages** in logs

### Performance

1. **Reuse connections** and publishers when possible
2. **Monitor memory usage** in long-running processes
3. **Implement graceful shutdown** for container orchestration
4. **Use appropriate queue durability** settings

## Troubleshooting

### Common Issues

| Issue | Symptoms | Solution |
|-------|----------|----------|
| Messages not routing | No activity in target queues | Check queue names and connection |
| Validation failures | High rejection rates | Verify message format |
| Republish loops | Repeated failures | Check failure handling logic |
| Memory leaks | Increasing memory usage | Monitor connection cleanup |

### Debug Information

Enable debug logging to see:
- Message validation details
- Queue operations
- Failure processing
- Connection status

### Monitoring Metrics

Track these metrics for operational health:
- Message processing rate
- Validation failure rate
- Republish frequency
- Queue depths
- Processing latency