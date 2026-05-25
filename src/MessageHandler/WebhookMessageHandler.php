<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Command\HandlePaymentFailedCommand;
use App\Command\HandlePaymentSuccessCommand;
use App\CommandHandler\HandlePaymentFailedHandler;
use App\CommandHandler\HandlePaymentSuccessHandler;
use App\Enum\WebhookType;
use App\Enum\SubscriptionPlan;
use App\Message\WebhookMessage;
use DateTimeImmutable;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class WebhookMessageHandler
{
    public function __construct(
        private HandlePaymentSuccessHandler $paymentSuccessHandler,
        private HandlePaymentFailedHandler $paymentFailedHandler,
    ) {
    }

    public function __invoke(WebhookMessage $message): void
    {
        $type = WebhookType::from($message->type);
        $period = SubscriptionPlan::from($message->period);
        $occurredAt = new DateTimeImmutable($message->occurredAt);

        match ($type) {
            WebhookType::PaymentSuccess => ($this->paymentSuccessHandler)(new HandlePaymentSuccessCommand(
                $message->externalEventId,
                $message->userId,
                $period,
                $occurredAt,
            )),
            WebhookType::PaymentFailed => ($this->paymentFailedHandler)(new HandlePaymentFailedCommand(
                $message->externalEventId,
                $message->userId,
                $period,
                $occurredAt,
            )),
        };
    }
}
