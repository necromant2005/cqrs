<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\WebhookType;
use App\Enum\SubscriptionPlan;
use DateTimeImmutable;

final readonly class ReceiveWebhookCommand
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $externalEventId,
        public WebhookType $type,
        public string $userId,
        public SubscriptionPlan $period,
        public DateTimeImmutable $occurredAt,
        public array $payload,
    ) {
    }
}
