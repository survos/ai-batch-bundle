<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Command;

use Tacman\AiBatch\Model\BatchResult;
use Tacman\AiBatch\Service\AiBatchBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replay a saved batch result JSONL without hitting the provider.
 *
 * Usage:
 *   bin/console batch:replay /platform/cheztac/ai-batches/batch_abc123.jsonl
 *
 * The app wires the actual result handler via --handler or by implementing
 * a tagged service.  This command just streams the results and outputs them,
 * or calls a registered handler service.
 */
#[AsCommand('batch:replay', 'Replay a saved batch result JSONL file without re-calling the provider')]
final class BatchReplayCommand
{
    public function __construct(
        private readonly AiBatchBuilder $builder,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to the saved .jsonl result file')]
        string $file,
        #[Option('Only show summary, do not apply results')]
        bool $dryRun = false,
        #[Option('Limit number of results to process')]
        ?int $limit = null,
    ): int {
        if (!is_file($file)) {
            $io->error("File not found: {$file}");
            return Command::FAILURE;
        }

        $io->title(sprintf('Replaying batch results from %s', basename($file)));

        $count   = 0;
        $success = 0;
        $failed  = 0;

        foreach ($this->builder->streamSavedResults($file) as $result) {
            /** @var BatchResult $result */
            if ($limit !== null && $count >= $limit) {
                break;
            }

            $count++;

            if (!$result->success) {
                $io->writeln(sprintf(
                    '  <error>FAIL</error> %s — %s',
                    $result->customId,
                    $result->error ?? $result->errorCode ?? 'unknown'
                ));
                $failed++;
                continue;
            }

            $success++;

            if ($dryRun) {
                $io->writeln(sprintf(
                    '  <info>OK</info>  %s (%d tokens)',
                    $result->customId,
                    ($result->promptTokens ?? 0) + ($result->outputTokens ?? 0)
                ));
            }
        }

        $io->success(sprintf(
            'Processed %d results: %d success, %d failed%s',
            $count,
            $success,
            $failed,
            $dryRun ? ' (dry-run — no changes applied)' : ''
        ));

        if (!$dryRun) {
            $io->note('To apply results, dispatch ApplyBatchResultsMessage or implement a tagged result handler.');
        }

        return Command::SUCCESS;
    }
}
