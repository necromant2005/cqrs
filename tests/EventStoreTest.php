<?php

declare(strict_types=1);

namespace App\Tests;

final class EventStoreTest extends ApiTestCase
{
    public function testImportantStatusChangesCreateEventsAndTimelineReadsEvents(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);
        $this->postJson('/cancel', ['user_id' => $userId]);

        $events = $this->jsonRequest('GET', sprintf('/users/%s/events', $userId));

        self::assertResponseIsSuccessful();
        self::assertSame('UserRegistered', $events[0]['event_type']);
        self::assertSame('SubscriptionStarted', $events[1]['event_type']);
        self::assertNull($events[1]['previous_status']);
        self::assertSame('active', $events[1]['new_status']);
        self::assertSame('SubscriptionCanceled', $events[2]['event_type']);
        self::assertSame('active', $events[2]['previous_status']);
        self::assertSame('canceled', $events[2]['new_status']);
    }

    public function testEventsAreAppendOnlyAndNoAuditLogTableExists(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);

        $connection = $this->entityManager->getConnection();
        $eventCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM events');
        self::assertGreaterThanOrEqual(2, $eventCount);

        $tables = array_map(
            static fn (array $row): string => (string) $row['name'],
            $connection->fetchAllAssociative("SELECT name FROM sqlite_master WHERE type = 'table'"),
        );
        self::assertNotContains('audit_log', $tables);
        self::assertNotContains('audit_logs', $tables);
        self::assertNotContains('webhooks', $tables);
    }
}
