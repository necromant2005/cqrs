<?php

declare(strict_types=1);

namespace App\CommandHandler;

use App\Command\SubscribeUserCommand;
use App\Entity\Subscription;
use App\Enum\EventType;
use App\Enum\SubscriptionStatus;
use App\EventStore\EventStore;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscribeUserHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private SubscriptionRepository $subscriptions,
        private EventStore $eventStore,
    ) {
    }

    public function __invoke(SubscribeUserCommand $command): Subscription
    {
        $user = $this->users->find($command->userId);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($user, $command): Subscription {
            $now = new DateTimeImmutable();
            $subscription = $this->subscriptions->findOneByUser($user);

            if ($subscription !== null) {
                if ($subscription->status() === SubscriptionStatus::Active && $subscription->currentPeriodEnd() > $now) {
                    throw new ConflictException('User already has an active subscription.');
                }

                if ($subscription->status() === SubscriptionStatus::Canceled && $subscription->currentPeriodEnd() > $now) {
                    throw new ConflictException('Canceled subscription is still active until current period end.');
                }
            }

            $periodStart = $now;
            $periodEnd = $periodStart->add(new DateInterval($command->plan->intervalSpec()));
            $previousStatus = $subscription?->status()->value;

            if ($subscription === null) {
                $subscription = new Subscription($user, $command->plan, SubscriptionStatus::Active, $periodStart, $periodEnd);
                $this->entityManager->persist($subscription);
                $this->entityManager->flush();
            } else {
                $subscription->activate($command->plan, $periodStart, $periodEnd);
                $this->entityManager->flush();
            }

            $this->eventStore->append(
                $subscription->id(),
                'Subscription',
                $user->id(),
                $subscription->id(),
                EventType::SubscriptionStarted,
                ['plan' => $command->plan->value],
                $previousStatus,
                SubscriptionStatus::Active->value,
            );
            $this->entityManager->flush();

            return $subscription;
        });
    }
}
