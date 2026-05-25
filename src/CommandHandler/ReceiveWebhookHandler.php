<?php

declare(strict_types=1);

namespace App\CommandHandler;

use App\Command\ReceiveWebhookCommand;
use App\Entity\Event;
use App\Enum\EventType;
use App\Exception\NotFoundException;
use App\Message\WebhookMessage;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ReceiveWebhookHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private EventRepository $events,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(ReceiveWebhookCommand $command): string
    {
        if ($this->events->hasExternalEvent($command->externalEventId, EventType::WebhookReceived)) {
            return $this->handleAlreadyReceived($command);
        }

        $user = $this->users->find($command->userId);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        $inserted = $this->insertWebhookReceived($command, $user->id());
        if (!$inserted) {
            return $this->handleAlreadyReceived($command);
        }

        $this->dispatchWebhookMessage($command);

        return 'queued';
    }

    private function handleAlreadyReceived(ReceiveWebhookCommand $command): string
    {
        if ($this->events->hasTerminalWebhookResult($command->externalEventId)) {
            return 'ignored';
        }

        $originalEvent = $this->events->findWebhookReceived($command->externalEventId);
        if ($originalEvent === null) {
            throw new NotFoundException('Webhook event not found.');
        }

        $this->dispatchStoredWebhookMessage($command->externalEventId, $originalEvent);

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

    private function dispatchStoredWebhookMessage(string $externalEventId, Event $event): void
    {
        $payload = $event->payload();
        $originalPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $userId = (string) ($originalPayload['user_id'] ?? $event->userId());

        $this->messageBus->dispatch(new WebhookMessage(
            $externalEventId,
            (string) $payload['type'],
            $userId,
            (string) $payload['period'],
            $event->occurredAt()->format(DATE_ATOM),
        ));
    }

    private function insertWebhookReceived(ReceiveWebhookCommand $command, string $userId): bool
    {
        $now = new \DateTimeImmutable();
        $affectedRows = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
INSERT OR IGNORE INTO events (
    id,
    aggregate_id,
    aggregate_type,
    user_id,
    subscription_id,
    event_type,
    payload,
    previous_status,
    new_status,
    external_event_id,
    occurred_at,
    created_at
) VALUES (
    :id,
    :aggregate_id,
    :aggregate_type,
    :user_id,
    NULL,
    :event_type,
    :payload,
    NULL,
    NULL,
    :external_event_id,
    :occurred_at,
    :created_at
)
SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'aggregate_id' => Uuid::v7()->toRfc4122(),
                'aggregate_type' => 'Webhook',
                'user_id' => $userId,
                'event_type' => EventType::WebhookReceived->value,
                'payload' => json_encode([
                    'type' => $command->type->value,
                    'period' => $command->period->value,
                    'payload' => $command->payload,
                ], JSON_THROW_ON_ERROR),
                'external_event_id' => $command->externalEventId,
                'occurred_at' => $command->occurredAt->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        return $affectedRows === 1;
    }
}
