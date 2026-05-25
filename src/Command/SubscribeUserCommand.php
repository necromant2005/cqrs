<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\SubscriptionPlan;

final readonly class SubscribeUserCommand
{
    public function __construct(public string $userId, public SubscriptionPlan $plan)
    {
    }
}
