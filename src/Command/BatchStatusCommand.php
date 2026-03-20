<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Service\OpenAiBatchClient;

#[AsCommand('ai:batch:status', 'Show the current status of an OpenAI batch job')]
final class BatchStatusCommand
{
    public function __construct(
        private readonly OpenAiBatchClient $client,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('OpenAI batch ID, e.g. batch_abc123')] string $batchId,
    ): int {
        $job = $this->client->checkBatch($batchId);

        $emoji = match (true) {
            $job->isComplete()   => '✅',
            $job->isFailed()     => '❌',
            $job->isProcessing() => '⏳',
            default              => '❓',
        };

        $io->table(
            ['Field', 'Value'],
            [
                ['Batch ID',      $job->id],
                ['Status',        $emoji . ' ' . $job->status],
                ['Total',         $job->totalCount],
                ['Completed',     $job->completedCount],
                ['Failed',        $job->failedCount],
                ['Output file',   $job->outputFileId ?? '(not ready)'],
                ['Error file',    $job->errorFileId  ?? 'none'],
                ['Created',       $job->createdAt   ? date('Y-m-d H:i:s', $job->createdAt)   : '-'],
                ['Completed at',  $job->completedAt ? date('Y-m-d H:i:s', $job->completedAt) : '-'],
                ['Expires at',    $job->expiresAt   ? date('Y-m-d H:i:s', $job->expiresAt)   : '-'],
            ]
        );

        if ($job->isComplete()) {
            $io->note([
                'Download results:',
                "  bin/console ai:batch:download {$batchId} --output=results.jsonl",
            ]);
        } elseif ($job->isProcessing()) {
            $io->note([
                'Wait for completion:',
                "  bin/console ai:batch:wait {$batchId}",
            ]);
        }

        return $job->isFailed() ? 1 : 0;
    }
}
