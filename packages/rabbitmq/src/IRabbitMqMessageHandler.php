<?php

declare(strict_types=1);

namespace Internals\RabbitMq;

interface IRabbitMqMessageHandler
{
    public function validate(string $messageBody): bool;
    public function consume(string $messageBody): bool;
}
