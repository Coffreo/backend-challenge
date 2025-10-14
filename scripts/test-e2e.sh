#!/bin/bash

# Script to run E2E test with custom value
# Usage: ./test-e2e.sh <value>
# Example: ./test-e2e.sh France

set -e

# Check if value argument is provided
if [ $# -eq 0 ]; then
    echo "Usage: $0 <value>"
    echo "Example: $0 France"
    echo "Example: $0 Paris"
    exit 1
fi

VALUE="$1"

echo "Running E2E test with value: $VALUE"
echo ""

# Send message to input queue via RabbitMQ API
RESPONSE=$(curl -s -u guest:guest -H "Content-Type: application/json" -X POST \
  -d "{\"properties\":{},\"routing_key\":\"input\",\"payload\":\"{\\\"value\\\":\\\"$VALUE\\\"}\",\"payload_encoding\":\"string\"}" \
  http://localhost:15672/api/exchanges/%2F/amq.default/publish)

# Check if message was routed
if echo "$RESPONSE" | grep -q '"routed":true'; then
    echo "Message successfully sent to input queue"
    echo "Payload: {\"value\":\"$VALUE\"}"
    echo ""
    echo "Monitor the logs with:"
    echo "docker compose -f docker-compose.dev.yml logs -f"
    echo ""
    echo "Or check specific worker logs:"
    echo "docker logs input_worker --tail 5"
    echo "docker logs country_worker --tail 5"
    echo "docker logs capital_worker --tail 5"
    echo "docker logs weather_worker --tail 5"
    echo "docker logs output_worker --tail 5"
    echo ""
    echo "Wait a few seconds and check output_worker logs for the final result!"
else
    echo "Failed to send message. Response: $RESPONSE"
    exit 1
fi
