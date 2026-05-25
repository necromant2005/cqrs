<?php

declare(strict_types=1);

namespace App\ReadModel;

final readonly class PendingWebhook
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
