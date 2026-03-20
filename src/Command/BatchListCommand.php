<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/**
 * List recent batches on the OpenAI account.
 *
 *   bin/console ai:batch:list
 *   bin/console ai:batch:list --limit=20
 */
#[AsCommand('ai:batch:list', 'List recent OpenAI batch jobs')]
final class BatchListCommand
{
    public function __construct(
        private readonly OpenAiBatchClient $client,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Max batches to show')] int $limit = 10,
    ): int {
        $batches = $this->client->listBatches($limit);

        if (empty($batches)) {
            $io->note('No batches found.');
            return 0;
        }

        $rows = [];
        foreach ($batches as $job) {
            $emoji = match (true) {
                $job->isComplete()   => '✅',
                $job->isFailed()     => '❌',
                $job->isProcessing() => '⏳',
                default              => '❓',
            };

            $progress = $job->totalCount > 0
                ? sprintf('%d/%d', $job->completedCount, $job->totalCount)
                : '-';

            $rows[] = [
                $job->id,
                $emoji . ' ' . $job->status,
                $progress,
                $job->failedCount > 0 ? (string)$job->failedCount : '-',
                $job->createdAt ? date('m-d H:i', $job->createdAt) : '-',
            ];
        }

        $io->table(['Batch ID', 'Status', 'Progress', 'Failed', 'Created'], $rows);
        $io->text(sprintf('Showing %d of your most recent batches.', count($rows)));

        return 0;
    }
}
