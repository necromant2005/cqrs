<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EventType;
use App\Repository\EventRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'events')]
#[ORM\Index(name: 'idx_events_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_events_external_event', columns: ['external_event_id'])]
class Event
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 36)]
    private string $aggregateId;

    #[ORM\Column(length: 50)]
    private string $aggregateType;

    #[ORM\Column(nullable: true)]
    private ?string $userId;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $subscriptionId;

    #[ORM\Column(length: 100, enumType: EventType::class)]
    private EventType $eventType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $previousStatus;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $newStatus;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalEventId;

    #[ORM\Column]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $aggregateId,
        string $aggregateType,
        ?string $userId,
        ?string $subscriptionId,
        EventType $eventType,
        array $payload,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $externalEventId,
        DateTimeImmutable $occurredAt,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->aggregateId = $aggregateId;
        $this->aggregateType = $aggregateType;
        $this->userId = $userId;
        $this->subscriptionId = $subscriptionId;
        $this->eventType = $eventType;
        $this->payload = $payload;
        $this->previousStatus = $previousStatus;
        $this->newStatus = $newStatus;
        $this->externalEventId = $externalEventId;
        $this->occurredAt = $occurredAt;
        $this->createdAt = new DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function eventType(): EventType
    {
        return $this->eventType;
    }

    public function previousStatus(): ?string
    {
        return $this->previousStatus;
    }

    public function newStatus(): ?string
    {
        return $this->newStatus;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
