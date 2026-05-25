<?php

declare(strict_types=1);

namespace App\Console;

use App\Enum\EventType;
use App\Message\WebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:webhooks:recover-pending', description: 'Dispatch stored webhook events that do not have a payment result yet.')]
final class RecoverPendingWebhooksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dispatched = 0;
        foreach ($this->findRecoverableWebhooks() as $webhook) {
            $this->messageBus->dispatch($this->messageFromRow($webhook));
            ++$dispatched;
        }

        $output->writeln(sprintf('Dispatched %d pending webhook message(s).', $dispatched));

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findRecoverableWebhooks(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            <<<'SQL'
SELECT received.external_event_id, received.user_id, received.payload, received.occurred_at
FROM events received
WHERE received.event_type = :received_type
  AND received.external_event_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM events terminal
      WHERE terminal.external_event_id = received.external_event_id
        AND terminal.event_type IN (:success_type, :failed_type)
  )
ORDER BY received.occurred_at ASC, received.created_at ASC
SQL,
            [
                'received_type' => EventType::WebhookReceived->value,
                'success_type' => EventType::PaymentSucceeded->value,
                'failed_type' => EventType::PaymentFailed->value,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function messageFromRow(array $row): WebhookMessage
    {
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $originalPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        return new WebhookMessage(
            (string) $row['external_event_id'],
            (string) $payload['type'],
            (string) ($originalPayload['user_id'] ?? $row['user_id']),
            (string) $payload['period'],
            (new \DateTimeImmutable((string) $row['occurred_at']))->format(DATE_ATOM),
        );
    }
}
