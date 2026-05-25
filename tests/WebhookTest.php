<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Subscription;
use App\Enum\SubscriptionStatus;
use App\Message\WebhookMessage;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class WebhookTest extends ApiTestCase
{
    public function testPaymentSuccessCreatesSubscriptionAndDispatchesMessage(): void
    {
        $userId = $this->registerUser();

        $response = $this->webhook('evt_success_create', 'payment_success', $userId);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', $response['status']);
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());

        $this->handleMessage('evt_success_create');
        $subscription = $this->subscription($userId);
        self::assertSame(SubscriptionStatus::Active, $subscription->status());
    }

    public function testPaymentSuccessExtendsFromCurrentPeriodEndIfStillActive(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);
        $firstEnd = $this->subscription($userId)->currentPeriodEnd();

        $this->webhook('evt_success_extend', 'payment_success', $userId);
        $this->handleMessage('evt_success_extend');

        $subscription = $this->subscription($userId);
        self::assertSame($firstEnd->format(DATE_ATOM), $subscription->currentPeriodStart()->format(DATE_ATOM));
        self::assertGreaterThan($firstEnd, $subscription->currentPeriodEnd());
    }

    public function testPaymentSuccessStartsFromNowIfPreviousPeriodExpired(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);
        $this->expireSubscription($userId, SubscriptionStatus::Expired);

        $this->webhook('evt_success_restart', 'payment_success', $userId);
        $this->handleMessage('evt_success_restart');

        $subscription = $this->subscription($userId);
        self::assertSame(SubscriptionStatus::Active, $subscription->status());
        self::assertGreaterThan(new DateTimeImmutable('-1 minute'), $subscription->currentPeriodStart());
    }

    public function testPaymentFailedChangesStatusToPastDueIfPeriodIsStillActive(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);

        $this->webhook('evt_failed_past_due', 'payment_failed', $userId);
        $this->handleMessage('evt_failed_past_due');

        self::assertSame(SubscriptionStatus::PastDue, $this->subscription($userId)->status());
    }

    public function testPaymentFailedChangesStatusToExpiredIfPeriodEnded(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);
        $this->expireSubscription($userId, SubscriptionStatus::Active);

        $this->webhook('evt_failed_expired', 'payment_failed', $userId);
        $this->handleMessage('evt_failed_expired');

        self::assertSame(SubscriptionStatus::Expired, $this->subscription($userId)->status());
    }

    public function testDuplicateWebhookExternalEventIdIsIgnored(): void
    {
        $userId = $this->registerUser();
        $this->webhook('evt_duplicate', 'payment_success', $userId);
        $response = $this->webhook('evt_duplicate', 'payment_success', $userId);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('ignored', $response['status']);
        $receivedCount = (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM events WHERE external_event_id = 'evt_duplicate' AND event_type = 'WebhookReceived'",
        );
        self::assertSame(1, $receivedCount);

        $this->handleMessage('evt_duplicate');
        $this->handleMessage('evt_duplicate');

        $count = (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM events WHERE external_event_id = 'evt_duplicate' AND event_type = 'PaymentSucceeded'",
        );
        self::assertSame(1, $count);
    }

    /** @return array<string, mixed> */
    private function webhook(string $externalEventId, string $type, string $userId): array
    {
        return $this->postJson('/webhooks/billing', [
            'external_event_id' => $externalEventId,
            'type' => $type,
            'user_id' => $userId,
            'period' => 'monthly',
            'occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    private function handleMessage(string $externalEventId): void
    {
        $event = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT payload, occurred_at FROM events WHERE external_event_id = ? AND event_type = 'WebhookReceived'",
            [$externalEventId],
        );
        self::assertIsArray($event);
        $payload = json_decode((string) $event['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $handler = static::getContainer()->get(\App\MessageHandler\WebhookMessageHandler::class);
        $handler(new WebhookMessage(
            $externalEventId,
            (string) $payload['type'],
            (string) $payload['payload']['user_id'],
            (string) $payload['period'],
            (new DateTimeImmutable((string) $event['occurred_at']))->format(DATE_ATOM),
        ));

        $this->entityManager->clear();
    }

    private function subscription(string $userId): Subscription
    {
        $user = static::getContainer()->get(UserRepository::class)->find($userId);
        self::assertNotNull($user);
        $subscription = static::getContainer()->get(SubscriptionRepository::class)->findOneByUser($user);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function expireSubscription(string $userId, SubscriptionStatus $status): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE subscriptions SET status = ?, current_period_end = ? WHERE user_id = ?',
            [$status->value, (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $userId],
        );
        $this->entityManager->clear();
    }
}
