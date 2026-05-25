<?php

declare(strict_types=1);

namespace App\Enum;

enum SubscriptionPlan: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function intervalSpec(): string
    {
        return match ($this) {
            self::Monthly => 'P1M',
            self::Yearly => 'P1Y',
        };
    }
}
