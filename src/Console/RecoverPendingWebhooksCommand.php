<?php

declare(strict_types=1);

namespace App\Console;

use App\Message\WebhookMessage;
use App\ReadModel\PendingWebhook;
use App\Repository\EventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:webhooks:recover-pending', description: 'Dispatch stored webhook events that do not have a payment result yet.')]
final class RecoverPendingWebhooksCommand extends Command
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dispatched = 0;
        foreach ($this->events->findRecoverableWebhooks() as $webhook) {
            $this->messageBus->dispatch($this->messageFromWebhook($webhook));
            ++$dispatched;
        }

        $output->writeln(sprintf('Dispatched %d pending webhook message(s).', $dispatched));

        return Command::SUCCESS;
    }

    private function messageFromWebhook(PendingWebhook $webhook): WebhookMessage
    {
        return new WebhookMessage(
            $webhook->externalEventId,
            $webhook->type,
            $webhook->userId,
            $webhook->period,
            $webhook->occurredAt,
        );
    }
}
