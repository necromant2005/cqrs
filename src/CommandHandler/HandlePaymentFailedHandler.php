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
use Symfony\Component\Uid\Uuid;

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
            if ($this->events->hasTerminalWebhookResult($command->externalEventId)) {
                return;
            }

            $subscription = $this->subscriptions->findOneByUser($user);
            if ($subscription === null) {
                $this->eventStore->append(
                    Uuid::v7()->toRfc4122(),
                    'Webhook',
                    $user->id(),
                    null,
                    EventType::WebhookProcessingFailed,
                    [
                        'type' => 'payment_failed',
                        'period' => $command->period->value,
                        'reason' => 'Subscription not found.',
                    ],
                    null,
                    null,
                    $command->externalEventId,
                    $command->occurredAt,
                );
                $this->entityManager->flush();

                return;
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
