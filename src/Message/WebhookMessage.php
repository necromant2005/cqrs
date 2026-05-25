<?php

declare(strict_types=1);

namespace App\Message;

final readonly class WebhookMessage
{
    public function __construct(
        public string $externalEventId,
        public string $type,
        public string $userId,
        public string $period,
        public string $occurredAt,
    ) {
    }
}
