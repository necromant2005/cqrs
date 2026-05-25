<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Enum\EventType;
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
