<?php

declare(strict_types=1);

namespace App\CommandHandler;

use App\Command\ResumeSubscriptionCommand;
use App\Entity\Subscription;
use App\Enum\EventType;
use App\Enum\SubscriptionStatus;
use App\EventStore\EventStore;
use App\Exception\ApiException;
use App\Exception\NotFoundException;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ResumeSubscriptionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private SubscriptionRepository $subscriptions,
        private EventStore $eventStore,
    ) {
    }

    public function __invoke(ResumeSubscriptionCommand $command): Subscription
    {
        $user = $this->users->find($command->userId);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($user): Subscription {
            $subscription = $this->subscriptions->findOneByUser($user);
            if ($subscription === null) {
                throw new NotFoundException('Subscription not found.');
            }

            if ($subscription->status() !== SubscriptionStatus::Canceled) {
                throw new ApiException('Only canceled subscription can be resumed.');
            }

            if ($subscription->currentPeriodEnd() <= new DateTimeImmutable()) {
                throw new ApiException('Subscription cannot be resumed after current period end.');
            }

            $previousStatus = $subscription->status()->value;
            $subscription->changeStatus(SubscriptionStatus::Active);
            $this->eventStore->append(
                $subscription->id(),
                'Subscription',
                $user->id(),
                $subscription->id(),
                EventType::SubscriptionResumed,
                [],
                $previousStatus,
                SubscriptionStatus::Active->value,
            );
            $this->entityManager->flush();

            return $subscription;
        });
    }
}
