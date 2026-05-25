<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\ReceiveWebhookCommand;
use App\CommandHandler\ReceiveWebhookHandler;
use App\Enum\WebhookType;
use App\Enum\SubscriptionPlan;
use App\Exception\BadRequestException;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use ValueError;

final class WebhookController extends AbstractController
{
    #[Route('/webhooks/billing', name: 'webhooks_billing', methods: ['POST'])]
    public function receive(Request $request, ReceiveWebhookHandler $handler): JsonResponse
    {
        $payload = $request->toArray();

        $externalEventId = trim((string) ($payload['external_event_id'] ?? ''));
        if ($externalEventId === '') {
            throw new BadRequestException('external_event_id is required.');
        }

        $occurredAt = $this->occurredAt((string) ($payload['occurred_at'] ?? ''));
        $status = $handler(new ReceiveWebhookCommand(
            $externalEventId,
            $this->type((string) ($payload['type'] ?? '')),
            (string) ($payload['user_id'] ?? ''),
            $this->period((string) ($payload['period'] ?? '')),
            $occurredAt,
            $payload,
        ));

        return $this->json([
            'external_event_id' => $externalEventId,
            'status' => $status,
        ], 202);
    }

    private function type(string $value): WebhookType
    {
        try {
            return WebhookType::from($value);
        } catch (ValueError) {
            throw new BadRequestException('type must be payment_success or payment_failed.');
        }
    }

    private function period(string $value): SubscriptionPlan
    {
        try {
            return SubscriptionPlan::from($value);
        } catch (ValueError) {
            throw new BadRequestException('period must be monthly or yearly.');
        }
    }

    private function occurredAt(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestException('occurred_at must be a valid datetime.');
        }
    }
}
