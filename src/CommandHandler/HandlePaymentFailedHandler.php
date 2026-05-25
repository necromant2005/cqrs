<?php

declare(strict_types=1);

namespace App\CommandHandler;

use App\Command\HandlePaymentFailedCommand;
use App\Enum\EventType;
use App\Enum\SubscriptionStatus;
use App\EventStore\EventStore;
use App\Exception\NotFoundException;
use App\Repository\EventRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HandlePaymentFailedHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private SubscriptionRepository $subscriptions,
        private EventRepository $events,
        private EventStore $eventStore,
    ) {
    }

    public function __invoke(HandlePaymentFailedCommand $command): void
    {
        $user = $this->users->find($command->userId);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        $this->entityManager->wrapInTransaction(function () use ($user, $command): void {
            if ($this->events->hasExternalEvent($command->externalEventId, EventType::PaymentFailed)) {
                return;
            }

            $subscription = $this->subscriptions->findOneByUser($user);
            if ($subscription === null) {
                throw new NotFoundException('Subscription not found.');
            }

            $now = new DateTimeImmutable();
            $previousStatus = $subscription->status()->value;
            $newStatus = $subscription->currentPeriodEnd() > $now
                ? SubscriptionStatus::PastDue
                : SubscriptionStatus::Expired;

            $this->eventStore->append(
                $subscription->id(),
                'Subscription',
                $user->id(),
                $subscription->id(),
                EventType::PaymentFailed,
                ['period' => $command->period->value],
                $previousStatus,
                $newStatus->value,
                $command->externalEventId,
                $command->occurredAt,
            );

            if ($subscription->status() !== $newStatus) {
                $subscription->changeStatus($newStatus);
                $this->eventStore->append(
                    $subscription->id(),
                    'Subscription',
                    $user->id(),
                    $subscription->id(),
                    $newStatus === SubscriptionStatus::PastDue ? EventType::SubscriptionPastDue : EventType::SubscriptionExpired,
                    [],
                    $previousStatus,
                    $newStatus->value,
                    $command->externalEventId,
                    $command->occurredAt,
                );
            }

            $this->entityManager->flush();
        });
    }
}
