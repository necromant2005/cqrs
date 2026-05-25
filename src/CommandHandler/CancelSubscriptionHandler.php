<?php

declare(strict_types=1);

namespace App\CommandHandler;

use App\Command\CancelSubscriptionCommand;
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

final readonly class CancelSubscriptionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private SubscriptionRepository $subscriptions,
        private EventStore $eventStore,
    ) {
    }

    public function __invoke(CancelSubscriptionCommand $command): Subscription
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

            $now = new DateTimeImmutable();
            if ($subscription->status() === SubscriptionStatus::Expired || $subscription->currentPeriodEnd() <= $now) {
                throw new ApiException('Expired subscription cannot be canceled.');
            }

            if ($subscription->status() === SubscriptionStatus::Canceled) {
                return $subscription;
            }

            $previousStatus = $subscription->status()->value;
            $subscription->changeStatus(SubscriptionStatus::Canceled);
            $this->eventStore->append(
                $subscription->id(),
                'Subscription',
                $user->id(),
                $subscription->id(),
                EventType::SubscriptionCanceled,
                [],
                $previousStatus,
                SubscriptionStatus::Canceled->value,
            );
            $this->entityManager->flush();

            return $subscription;
        });
    }
}
