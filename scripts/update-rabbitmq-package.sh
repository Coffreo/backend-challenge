#!/bin/bash

# Script to update internals/rabbitmq package across all projects
# Usage: ./update-rabbitmq-package.sh

set -e

# Array of project containers
PROJECTS=("input-worker" "country-worker" "capital-worker" "weather-worker" "output-worker")

echo "Updating internals/rabbitmq package across all projects..."

for PROJECT in "${PROJECTS[@]}"; do
    echo "Processing project: $PROJECT"

    # Check if container is running
    if ! docker compose -f docker-compose.dev.yml ps --services --filter "status=running" | grep -q "^$PROJECT$"; then
        echo "Container $PROJECT is not running. Skipping to next."
        continue
    fi

    echo "Removing vendor/internals/rabbitmq directory..."
    docker compose -f docker-compose.dev.yml exec "$PROJECT" rm -rf vendor/internals/rabbitmq

    echo "Updating package via composer..."
    docker compose -f docker-compose.dev.yml exec "$PROJECT" composer update internals/rabbitmq

    echo "Project $PROJECT updated successfully"

    echo "Restarting $PROJECT..."
    docker compose -f docker-compose.dev.yml restart "$PROJECT"

    echo ""
done

echo "Update completed for all projects!"
