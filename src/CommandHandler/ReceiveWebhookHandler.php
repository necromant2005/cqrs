<?php

declare(strict_types=1);

namespace App\CommandHandler;

use App\Command\ReceiveWebhookCommand;
use App\Enum\EventType;
use App\EventStore\EventStore;
use App\Exception\NotFoundException;
use App\Message\WebhookMessage;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ReceiveWebhookHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private EventRepository $events,
        private EventStore $eventStore,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(ReceiveWebhookCommand $command): string
    {
        $user = $this->users->find($command->userId);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        if ($this->events->hasExternalEvent($command->externalEventId, EventType::WebhookReceived)) {
            return $this->handleAlreadyReceived($command);
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $command): void {
                $this->eventStore->append(
                    Uuid::v7()->toRfc4122(),
                    'Webhook',
                    $user->id(),
                    null,
                    EventType::WebhookReceived,
                    [
                        'type' => $command->type->value,
                        'period' => $command->period->value,
                        'payload' => $command->payload,
                    ],
                    null,
                    null,
                    $command->externalEventId,
                    $command->occurredAt,
                );
                $this->entityManager->flush();
            });
        } catch (UniqueConstraintViolationException) {
            $this->entityManager->clear();

            return $this->handleAlreadyReceived($command);
        }

        $this->dispatchWebhookMessage($command);

        return 'queued';
    }

    private function handleAlreadyReceived(ReceiveWebhookCommand $command): string
    {
        if ($this->events->hasPaymentResult($command->externalEventId)) {
            return 'ignored';
        }

        $this->dispatchWebhookMessage($command);

        return 'queued';
    }

    private function dispatchWebhookMessage(ReceiveWebhookCommand $command): void
    {
        $this->messageBus->dispatch(new WebhookMessage(
            $command->externalEventId,
            $command->type->value,
            $command->userId,
            $command->period->value,
            $command->occurredAt->format(DATE_ATOM),
        ));
    }
}
