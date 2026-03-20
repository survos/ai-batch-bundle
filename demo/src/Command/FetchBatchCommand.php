<?php
declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Model\BatchJob;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/**
 * Check the status of a submitted batch and display results when ready.
 *
 *   bin/console app:fetch-batch 1
 *   bin/console app:fetch-batch 1 --watch                    # polls every 30s until done
 *   bin/console app:fetch-batch 1 --output=results/2.jsonl  # save output to file
 */
#[AsCommand('app:fetch-batch', 'Check status and fetch results of a submitted AI batch')]
final class FetchBatchCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OpenAiBatchClient      $client,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Local AiBatch ID (from app:advertising --batch output)')] int $batchId,
        #[Option('Poll every 30 seconds until complete')] bool $watch = false,
        #[Option('Save results to file')] ?string $output = null,
    ): int {
        $batch = $this->em->find(AiBatch::class, $batchId);

        if (!$batch) {
            $io->error("No batch found with ID {$batchId}");
            return Command::FAILURE;
        }

        // Default output path: results/{providerBatchId}.jsonl
        if ($output === null && $batch->outputFileId) {
            $output = 'results/' . $batch->providerBatchId . '.jsonl';
        }

        // Skip if already downloaded
        if ($output && file_exists($output)) {
            $io->success(sprintf('Results already saved to %s', $output));
            return Command::SUCCESS;
        }

        $io->title(sprintf('Batch #%d — %s', $batchId, $batch->task));

        do {
            // Refresh status from provider
            $job = $this->client->checkBatch($batch->providerBatchId);
            $batch->applyProviderStatus(
                $job->status,
                $job->completedCount,
                $job->failedCount,
                $job->outputFileId,
                $job->errorFileId,
            );
            $this->em->flush();

            $this->printStatus($io, $batch, $job);

            if ($batch->isComplete()) {
                $this->printResults($io, $batch, $output);
                return Command::SUCCESS;
            }

            if ($batch->isFailed()) {
                $io->error(sprintf('Batch %s. Check OpenAI dashboard for details.', $batch->status));
                return Command::FAILURE;
            }

            if ($watch) {
                $io->writeln('  Waiting 30 seconds...');
                sleep(30);
            }

        } while ($watch && $batch->isProcessing());

        if (!$batch->isComplete()) {
            $io->note([
                'Still processing. Check again with:',
                sprintf('  bin/console app:fetch-batch %d', $batchId),
                '',
                'Or watch continuously with:',
                sprintf('  bin/console app:fetch-batch %d --watch', $batchId),
            ]);
        }

        return Command::SUCCESS;
    }

    private function printStatus(SymfonyStyle $io, AiBatch $batch, \Tacman\AiBatch\Model\BatchJob $job): void
    {
        $statusEmoji = match (true) {
            $job->isComplete()   => '✅',
            $job->isFailed()     => '❌',
            $job->isProcessing() => '⏳',
            default              => '❓',
        };

        $io->table(
            ['Field', 'Value'],
            [
                ['Status',         $statusEmoji . ' ' . $job->status],
                ['Provider ID',    $batch->providerBatchId],
                ['Progress',       sprintf('%d / %d (failed: %d)', $job->completedCount, $job->totalCount, $job->failedCount)],
                ['Submitted',      $batch->submittedAt?->format('Y-m-d H:i:s') ?? '-'],
                ['Last polled',    $batch->lastPolledAt?->format('Y-m-d H:i:s') ?? 'just now'],
            ]
        );
    }

    private function printResults(SymfonyStyle $io, AiBatch $batch, ?string $output): void
    {
        $io->section('🎉 Results');

        $count = 0;
        $lines = [];

        foreach ($this->client->fetchResults(
            new BatchJob(
                id:           $batch->providerBatchId,
                status:       'completed',
                provider:     'openai',
                outputFileId: $batch->outputFileId,
            )
        ) as $result) {
            if (!$result->success) {
                $io->writeln(sprintf('  ❌ <error>%s: %s</error>', $result->customId, $result->error));
                $lines[] = json_encode(['custom_id' => $result->customId, 'error' => $result->error]);
                continue;
            }

            // Parse product id from custom_id "product_{id}"
            $productId = str_replace('product_', '', $result->customId);
            $copy      = is_string($result->content) ? $result->content : json_encode($result->content);

            $io->writeln(sprintf('<info>Product #%s</info>', $productId));
            $io->writeln(sprintf('  %s', $copy));
            $io->newLine();

            $lines[] = json_encode([
                'custom_id' => $result->customId,
                'response'  => ['content' => $copy],
            ]);

            $count++;
            $batch->appliedCount = $count;
        }

        $this->em->flush();

        // Save to file if requested
        if ($output) {
            @mkdir(dirname($output), 0775, true);
            file_put_contents($output, implode("\n", $lines) . "\n");
            $io->success(sprintf('%d results saved to %s', $count, $output));
        } else {
            $io->success(sprintf(
                '%d results displayed. Tokens used: ~%d prompt + ~%d output.',
                $count,
                $count * 300,  // rough estimate for low-res vision
                $count * 150,
            ));
        }
    }
}
