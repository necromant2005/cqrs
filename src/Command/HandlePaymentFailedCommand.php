<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\SubscriptionPlan;
use DateTimeImmutable;

final readonly class HandlePaymentFailedCommand
{
    public function __construct(
        public string $externalEventId,
        public string $userId,
        public SubscriptionPlan $period,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
