<?php

declare(strict_types=1);

namespace App\Enum;

enum WebhookType: string
{
    case PaymentSuccess = 'payment_success';
    case PaymentFailed = 'payment_failed';
}
