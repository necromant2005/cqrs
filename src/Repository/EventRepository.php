<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Enum\EventType;
use App\ReadModel\PendingWebhook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Event> */
final class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /** @return list<Event> */
    public function findTimelineForUser(string $userId): array
    {
        return $this->createQueryBuilder('event')
            ->andWhere('event.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('event.occurredAt', 'ASC')
            ->addOrderBy('event.createdAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasExternalEvent(string $externalEventId, EventType $eventType): bool
    {
        return (bool) $this->createQueryBuilder('event')
            ->select('1')
            ->andWhere('event.externalEventId = :externalEventId')
            ->andWhere('event.eventType = :eventType')
            ->setParameter('externalEventId', $externalEventId)
            ->setParameter('eventType', $eventType)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasPaymentResult(string $externalEventId): bool
    {
        return $this->hasAnyExternalEvent($externalEventId, [
            EventType::PaymentSucceeded,
            EventType::PaymentFailed,
        ]);
    }

    public function hasTerminalWebhookResult(string $externalEventId): bool
    {
        return $this->hasAnyExternalEvent($externalEventId, [
            EventType::PaymentSucceeded,
            EventType::PaymentFailed,
        ]);
    }

    public function findWebhookReceived(string $externalEventId): ?Event
    {
        return $this->createQueryBuilder('event')
            ->andWhere('event.externalEventId = :externalEventId')
            ->andWhere('event.eventType = :eventType')
            ->setParameter('externalEventId', $externalEventId)
            ->setParameter('eventType', EventType::WebhookReceived)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<PendingWebhook> */
    public function findRecoverableWebhooks(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
SELECT received.external_event_id, received.user_id, received.payload, received.occurred_at
FROM events received
WHERE received.event_type = :received_type
  AND received.external_event_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM events terminal
      WHERE terminal.external_event_id = received.external_event_id
        AND terminal.event_type IN (:success_type, :failed_type)
  )
ORDER BY received.occurred_at ASC, received.created_at ASC
SQL,
            [
                'received_type' => EventType::WebhookReceived->value,
                'success_type' => EventType::PaymentSucceeded->value,
                'failed_type' => EventType::PaymentFailed->value,
            ],
        );

        return array_values(array_map(function (array $row): PendingWebhook {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $originalPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

            return new PendingWebhook(
                (string) $row['external_event_id'],
                (string) $payload['type'],
                (string) ($originalPayload['user_id'] ?? $row['user_id']),
                (string) $payload['period'],
                (new \DateTimeImmutable((string) $row['occurred_at']))->format(DATE_ATOM),
            );
        }, $rows));
    }

    /**
     * @param list<EventType> $eventTypes
     */
    public function hasAnyExternalEvent(string $externalEventId, array $eventTypes): bool
    {
        return (bool) $this->createQueryBuilder('event')
            ->select('1')
            ->andWhere('event.externalEventId = :externalEventId')
            ->andWhere('event.eventType IN (:eventTypes)')
            ->setParameter('externalEventId', $externalEventId)
            ->setParameter('eventTypes', $eventTypes)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
