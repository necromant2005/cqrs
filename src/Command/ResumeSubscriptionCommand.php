<?php

declare(strict_types=1);

namespace App\Command;

final readonly class ResumeSubscriptionCommand
{
    public function __construct(public string $userId)
    {
    }
}
