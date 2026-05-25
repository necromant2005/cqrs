<?php

declare(strict_types=1);

namespace App\Command;

final readonly class CancelSubscriptionCommand
{
    public function __construct(public string $userId)
    {
    }
}
