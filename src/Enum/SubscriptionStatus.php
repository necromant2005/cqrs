<?php

declare(strict_types=1);

namespace App\Enum;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Canceled = 'canceled';
    case PastDue = 'past_due';
    case Expired = 'expired';
}
