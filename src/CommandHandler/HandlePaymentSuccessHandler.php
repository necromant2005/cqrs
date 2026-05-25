<?php

declare(strict_types=1);

namespace App\CommandHandler;

use App\Command\HandlePaymentSuccessCommand;
use App\Entity\Subscription;
use App\Enum\EventType;
use App\Enum\SubscriptionStatus;
use App\EventStore\EventStore;
use App\Exception\NotFoundException;
use App\Repository\EventRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HandlePaymentSuccessHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private SubscriptionRepository $subscriptions,
        private EventRepository $events,
        private EventStore $eventStore,
    ) {
    }

    public function __invoke(HandlePaymentSuccessCommand $command): void
    {
        $user = $this->users->find($command->userId);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $command): void {
                if ($this->events->hasTerminalWebhookResult($command->externalEventId)) {
                    return;
                }

                $now = new DateTimeImmutable();
                $subscription = $this->subscriptions->findOneByUser($user);
                $previousStatus = $subscription?->status()->value;
                $periodStart = ($subscription !== null && $subscription->currentPeriodEnd() > $now && $subscription->status() !== SubscriptionStatus::Expired)
                    ? $subscription->currentPeriodEnd()
                    : $now;
                $periodEnd = $periodStart->add(new DateInterval($command->period->intervalSpec()));

                if ($subscription === null) {
                    $subscription = new Subscription($user, $command->period, SubscriptionStatus::Active, $periodStart, $periodEnd);
                    $this->entityManager->persist($subscription);
                    $this->entityManager->flush();
                } else {
                    $subscription->activate($command->period, $periodStart, $periodEnd);
                    $this->entityManager->flush();
                }

                $this->eventStore->append(
                    $subscription->id(),
                    'Subscription',
                    $user->id(),
                    $subscription->id(),
                    EventType::PaymentSucceeded,
                    [
                        'period' => $command->period->value,
                        'current_period_start' => $periodStart->format(DATE_ATOM),
                        'current_period_end' => $periodEnd->format(DATE_ATOM),
                    ],
                    $previousStatus,
                    SubscriptionStatus::Active->value,
                    $command->externalEventId,
                    $command->occurredAt,
                );
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (!$this->paymentResultExists($command->externalEventId)) {
                throw $exception;
            }
        }
    }

    private function paymentResultExists(string $externalEventId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            "SELECT 1 FROM events WHERE external_event_id = ? AND event_type IN ('PaymentSucceeded', 'PaymentFailed') LIMIT 1",
            [$externalEventId],
        );
    }
}
