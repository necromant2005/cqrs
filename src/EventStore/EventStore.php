<?php

declare(strict_types=1);

namespace App\EventStore;

use App\Entity\Event;
use App\Enum\EventType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EventStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function append(
        string $aggregateId,
        string $aggregateType,
        ?string $userId,
        ?string $subscriptionId,
        EventType $eventType,
        array $payload = [],
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?string $externalEventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ): Event {
        $event = new Event(
            $aggregateId,
            $aggregateType,
            $userId,
            $subscriptionId,
            $eventType,
            $payload,
            $previousStatus,
            $newStatus,
            $externalEventId,
            $occurredAt ?? new DateTimeImmutable(),
        );

        $this->entityManager->persist($event);

        return $event;
    }
}
