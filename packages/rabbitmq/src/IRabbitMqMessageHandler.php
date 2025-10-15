<?php

declare(strict_types=1);

namespace Internals\RabbitMq;

interface IRabbitMqMessageHandler
{
    public function validate(string $messageBody, array $properties): bool;
    public function consume(string $messageBody, array $properties): bool;
}
