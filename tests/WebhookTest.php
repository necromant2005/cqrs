<?php

declare(strict_types=1);

namespace App\Tests;

use App\Console\RecoverPendingWebhooksCommand;
use App\Entity\Subscription;
use App\Enum\SubscriptionStatus;
use App\Message\WebhookMessage;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

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

    public function testDuplicateWebhookBeforePaymentResultIsQueuedAgain(): void
    {
        $userId = $this->registerUser();
        $this->webhook('evt_duplicate', 'payment_success', $userId);
        $this->resetAsyncTransport();
        $response = $this->webhook('evt_duplicate', 'payment_success', $userId);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', $response['status']);
        self::assertSame(1, $this->countEvents('evt_duplicate', 'WebhookReceived'));
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
    }

    public function testDuplicateWebhookBeforePaymentResultReplaysOriginalPayload(): void
    {
        $originalUserId = $this->registerUser('original@example.com');
        $otherUserId = $this->registerUser('other@example.com');
        $this->webhook('evt_payload_replay', 'payment_success', $originalUserId);
        $this->resetAsyncTransport();

        $response = $this->webhook('evt_payload_replay', 'payment_failed', $otherUserId);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', $response['status']);
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(WebhookMessage::class, $message);
        self::assertSame('payment_success', $message->type);
        self::assertSame($originalUserId, $message->userId);
    }

    public function testDuplicateWebhookWithUnknownRetryUserStillReplaysOriginalPayload(): void
    {
        $originalUserId = $this->registerUser();
        $this->webhook('evt_unknown_retry_user', 'payment_success', $originalUserId);
        $this->resetAsyncTransport();

        $response = $this->webhook('evt_unknown_retry_user', 'payment_failed', Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', $response['status']);
    }

    public function testDuplicateWebhookAfterPaymentSuccessIsIgnored(): void
    {
        $userId = $this->registerUser();
        $this->webhook('evt_duplicate_success', 'payment_success', $userId);
        $this->handleMessage('evt_duplicate_success');

        $response = $this->webhook('evt_duplicate_success', 'payment_success', $userId);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('ignored', $response['status']);
        self::assertSame(1, $this->countEvents('evt_duplicate_success', 'WebhookReceived'));
        self::assertSame(1, $this->countEvents('evt_duplicate_success', 'PaymentSucceeded'));
    }

    public function testDuplicateWebhookAfterPaymentFailureIsIgnored(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);
        $this->webhook('evt_duplicate_failed', 'payment_failed', $userId);
        $this->handleMessage('evt_duplicate_failed');

        $response = $this->webhook('evt_duplicate_failed', 'payment_failed', $userId);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('ignored', $response['status']);
        self::assertSame(1, $this->countEvents('evt_duplicate_failed', 'WebhookReceived'));
        self::assertSame(1, $this->countEvents('evt_duplicate_failed', 'PaymentFailed'));
    }

    public function testPaymentFailureAfterPaymentSuccessWithSameExternalEventIsSkipped(): void
    {
        $userId = $this->registerUser();
        $this->invokeWebhookMessage('evt_terminal_success', 'payment_success', $userId);
        $this->invokeWebhookMessage('evt_terminal_success', 'payment_failed', $userId);

        self::assertSame(1, $this->countEvents('evt_terminal_success', 'PaymentSucceeded'));
        self::assertSame(0, $this->countEvents('evt_terminal_success', 'PaymentFailed'));
        self::assertSame(SubscriptionStatus::Active, $this->subscription($userId)->status());
    }

    public function testPaymentSuccessAfterPaymentFailureWithSameExternalEventIsSkipped(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);
        $this->invokeWebhookMessage('evt_terminal_failed', 'payment_failed', $userId);
        $this->invokeWebhookMessage('evt_terminal_failed', 'payment_success', $userId);

        self::assertSame(1, $this->countEvents('evt_terminal_failed', 'PaymentFailed'));
        self::assertSame(0, $this->countEvents('evt_terminal_failed', 'PaymentSucceeded'));
        self::assertSame(SubscriptionStatus::PastDue, $this->subscription($userId)->status());
    }

    public function testPaymentFailureWithoutSubscriptionRecordsProcessingFailure(): void
    {
        $userId = $this->registerUser();
        $this->webhook('evt_failed_without_subscription', 'payment_failed', $userId);
        $this->handleMessage('evt_failed_without_subscription');

        self::assertSame(1, $this->countEvents('evt_failed_without_subscription', 'WebhookProcessingFailed'));
        self::assertSame(0, $this->countEvents('evt_failed_without_subscription', 'PaymentFailed'));

        $response = $this->webhook('evt_failed_without_subscription', 'payment_failed', $userId);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', $response['status']);
    }

    public function testPaymentFailureCanBeProcessedAfterEarlierProcessingFailure(): void
    {
        $userId = $this->registerUser();
        $this->webhook('evt_failed_then_valid', 'payment_failed', $userId);
        $this->handleMessage('evt_failed_then_valid');
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);

        $this->webhook('evt_failed_then_valid', 'payment_failed', $userId);
        $this->handleMessage('evt_failed_then_valid');

        self::assertSame(1, $this->countEvents('evt_failed_then_valid', 'WebhookProcessingFailed'));
        self::assertSame(1, $this->countEvents('evt_failed_then_valid', 'PaymentFailed'));
        self::assertSame(SubscriptionStatus::PastDue, $this->subscription($userId)->status());
    }

    public function testRepeatedMissingSubscriptionFailureDoesNotDuplicateProcessingFailure(): void
    {
        $userId = $this->registerUser();
        $this->webhook('evt_repeated_missing_subscription', 'payment_failed', $userId);
        $this->handleMessage('evt_repeated_missing_subscription');
        $this->handleMessage('evt_repeated_missing_subscription');

        self::assertSame(1, $this->countEvents('evt_repeated_missing_subscription', 'WebhookProcessingFailed'));
        self::assertSame(0, $this->countEvents('evt_repeated_missing_subscription', 'PaymentFailed'));
    }

    public function testExternalEventTypeIsUniqueInEventStore(): void
    {
        $connection = $this->entityManager->getConnection();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $params = [
            'aggregate_id' => Uuid::v7()->toRfc4122(),
            'aggregate_type' => 'Webhook',
            'event_type' => 'WebhookReceived',
            'payload' => json_encode(['type' => 'payment_success'], JSON_THROW_ON_ERROR),
            'external_event_id' => 'evt_unique_guard',
            'occurred_at' => $now,
            'created_at' => $now,
        ];

        $connection->insert('events', ['id' => Uuid::v7()->toRfc4122()] + $params);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('events', ['id' => Uuid::v7()->toRfc4122()] + $params);
    }

    public function testExternalEventCanHaveOnlyOneTerminalWebhookResult(): void
    {
        $connection = $this->entityManager->getConnection();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $params = [
            'aggregate_id' => Uuid::v7()->toRfc4122(),
            'aggregate_type' => 'Subscription',
            'payload' => json_encode(['period' => 'monthly'], JSON_THROW_ON_ERROR),
            'external_event_id' => 'evt_terminal_guard',
            'occurred_at' => $now,
            'created_at' => $now,
        ];

        $connection->insert('events', ['id' => Uuid::v7()->toRfc4122(), 'event_type' => 'PaymentSucceeded'] + $params);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('events', ['id' => Uuid::v7()->toRfc4122(), 'event_type' => 'PaymentFailed'] + $params);
    }

    public function testProcessingFailureDoesNotConsumeTerminalResultSlot(): void
    {
        $connection = $this->entityManager->getConnection();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $params = [
            'aggregate_id' => Uuid::v7()->toRfc4122(),
            'aggregate_type' => 'Webhook',
            'payload' => json_encode(['period' => 'monthly'], JSON_THROW_ON_ERROR),
            'external_event_id' => 'evt_non_terminal_failure',
            'occurred_at' => $now,
            'created_at' => $now,
        ];

        $connection->insert('events', ['id' => Uuid::v7()->toRfc4122(), 'event_type' => 'WebhookProcessingFailed'] + $params);
        $connection->insert('events', ['id' => Uuid::v7()->toRfc4122(), 'event_type' => 'PaymentFailed'] + $params);

        self::assertSame(1, $this->countEvents('evt_non_terminal_failure', 'WebhookProcessingFailed'));
        self::assertSame(1, $this->countEvents('evt_non_terminal_failure', 'PaymentFailed'));
    }

    public function testRecoveryCommandDispatchesPendingWebhook(): void
    {
        $userId = $this->registerUser();
        $this->webhook('evt_recover_pending', 'payment_success', $userId);
        $this->resetAsyncTransport();

        $tester = $this->recoverPendingWebhooksTester();
        $tester->execute([]);

        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(WebhookMessage::class, $message);
        self::assertSame('evt_recover_pending', $message->externalEventId);
        self::assertStringContainsString('Dispatched 1 pending webhook message(s).', $tester->getDisplay());
    }

    public function testRecoveryCommandSkipsProcessedWebhook(): void
    {
        $userId = $this->registerUser();
        $this->webhook('evt_recover_processed', 'payment_success', $userId);
        $this->handleMessage('evt_recover_processed');
        $this->resetAsyncTransport();

        $tester = $this->recoverPendingWebhooksTester();
        $tester->execute([]);

        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(0, $transport->getSent());
        self::assertStringContainsString('Dispatched 0 pending webhook message(s).', $tester->getDisplay());
    }

    public function testRakeWorkerRunsRecoveryBeforeMessengerConsumer(): void
    {
        $rakefile = file_get_contents(__DIR__ . '/../Rakefile');
        self::assertIsString($rakefile);
        self::assertStringContainsString("docker compose exec app php bin/console app:webhooks:recover-pending'\n  sh 'docker compose exec app php bin/console messenger:consume async -vv", $rakefile);
    }

    public function testMessengerQueueNameIsEvents(): void
    {
        $config = file_get_contents(__DIR__ . '/../config/packages/framework.yaml');
        self::assertIsString($config);
        self::assertStringContainsString('queue_name: events', $config);
    }

    /** @return array<string, mixed> */
    private function webhook(string $externalEventId, string $type, string $userId, string $period = 'monthly'): array
    {
        return $this->postJson('/webhooks/billing', [
            'external_event_id' => $externalEventId,
            'type' => $type,
            'user_id' => $userId,
            'period' => $period,
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
        $handler($this->webhookMessage(
            $externalEventId,
            (string) $payload['type'],
            (string) $payload['payload']['user_id'],
            (string) $payload['period'],
            (new DateTimeImmutable((string) $event['occurred_at']))->format(DATE_ATOM),
        ));

        $this->entityManager->clear();
    }

    private function invokeWebhookMessage(string $externalEventId, string $type, string $userId): void
    {
        $handler = static::getContainer()->get(\App\MessageHandler\WebhookMessageHandler::class);
        $handler($this->webhookMessage(
            $externalEventId,
            $type,
            $userId,
            'monthly',
            (new DateTimeImmutable())->format(DATE_ATOM),
        ));

        $this->entityManager->clear();
    }

    private function resetAsyncTransport(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
    }

    private function recoverPendingWebhooksTester(): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('app:webhooks:recover-pending');
        self::assertInstanceOf(RecoverPendingWebhooksCommand::class, $command);

        return new CommandTester($command);
    }

    private function webhookMessage(
        string $externalEventId,
        string $type,
        string $userId,
        string $period,
        string $occurredAt,
    ): WebhookMessage {
        return new WebhookMessage($externalEventId, $type, $userId, $period, $occurredAt);
    }

    private function countEvents(string $externalEventId, string $eventType): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM events WHERE external_event_id = ? AND event_type = ?',
            [$externalEventId, $eventType],
        );
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
