<?php

declare(strict_types=1);

namespace App\Enum;

enum EventType: string
{
    case UserRegistered = 'UserRegistered';
    case SubscriptionStarted = 'SubscriptionStarted';
    case SubscriptionCanceled = 'SubscriptionCanceled';
    case SubscriptionResumed = 'SubscriptionResumed';
    case PaymentSucceeded = 'PaymentSucceeded';
    case PaymentFailed = 'PaymentFailed';
    case SubscriptionPastDue = 'SubscriptionPastDue';
    case SubscriptionExpired = 'SubscriptionExpired';
    case WebhookReceived = 'WebhookReceived';
    case WebhookIgnoredAsDuplicate = 'WebhookIgnoredAsDuplicate';
}
