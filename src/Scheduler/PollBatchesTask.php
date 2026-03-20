<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Component\Messenger\MessageBusInterface;
use Tacman\AiBatch\Message\PollBatchesMessage;

/**
 * Runs every 2 minutes. Dispatches a message to check all in-progress batches.
 *
 * The actual polling happens in PollBatchesMessageHandler to keep
 * the scheduler task lightweight and avoid blocking the scheduler worker.
 *
 * Register as a service to enable:
 *   bin/console messenger:consume scheduler_default
 */
#[AsCronTask('*/2 * * * *', schedule: 'default')]
final class PollBatchesTask
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {}

    public function __invoke(): void
    {
        $this->bus->dispatch(new PollBatchesMessage());
    }
}
