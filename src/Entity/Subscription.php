<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SubscriptionPlan;
use App\Enum\SubscriptionStatus;
use App\Repository\SubscriptionRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscriptions')]
#[ORM\UniqueConstraint(name: 'uniq_subscriptions_user', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20, enumType: SubscriptionPlan::class)]
    private SubscriptionPlan $plan;

    #[ORM\Column(length: 20, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status;

    #[ORM\Column]
    private DateTimeImmutable $currentPeriodStart;

    #[ORM\Column]
    private DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        SubscriptionPlan $plan,
        SubscriptionStatus $status,
        DateTimeImmutable $currentPeriodStart,
        DateTimeImmutable $currentPeriodEnd,
    ) {
        $now = new DateTimeImmutable();
        $this->id = Uuid::v7()->toRfc4122();
        $this->user = $user;
        $this->plan = $plan;
        $this->status = $status;
        $this->currentPeriodStart = $currentPeriodStart;
        $this->currentPeriodEnd = $currentPeriodEnd;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function plan(): SubscriptionPlan
    {
        return $this->plan;
    }

    public function status(): SubscriptionStatus
    {
        return $this->status;
    }

    public function currentPeriodStart(): DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function currentPeriodEnd(): DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function accessActive(DateTimeImmutable $now): bool
    {
        if ($this->status === SubscriptionStatus::Expired) {
            return false;
        }

        return $this->currentPeriodEnd > $now;
    }

    public function activate(
        SubscriptionPlan $plan,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
    ): void {
        $this->plan = $plan;
        $this->status = SubscriptionStatus::Active;
        $this->currentPeriodStart = $periodStart;
        $this->currentPeriodEnd = $periodEnd;
        $this->touch();
    }

    public function changeStatus(SubscriptionStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
