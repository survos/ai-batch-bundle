<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/**
 * Download results for a completed batch.
 *
 *   bin/console ai:batch:download batch_abc123 --output=results.jsonl
 *   bin/console ai:batch:download batch_abc123 --output=results.jsonl | jq
 *
 * Returns Command::FAILURE (exit code 1) if the batch is not yet complete.
 * This makes it easy to script:
 *
 *   bin/console ai:batch:download batch_abc123 --output=results.jsonl \
 *     && cat results.jsonl | jq '.response.body.choices[0].message.content'
 */
#[AsCommand('ai:batch:download', 'Download results for a completed OpenAI batch (fails if not ready)')]
final class BatchDownloadCommand
{
    public function __construct(
        private readonly OpenAiBatchClient $client,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('OpenAI batch ID')] string $batchId,
        #[Option('Output file path (default: stdout)')] ?string $output = null,
        #[Option('Pretty-print JSON output')] bool $pretty = false,
    ): int {
        $job = $this->client->checkBatch($batchId);

        if ($job->isProcessing()) {
            $io->warning(sprintf(
                'Batch %s is not complete yet (status: %s, %d/%d done).',
                $batchId, $job->status, $job->completedCount, $job->totalCount
            ));
            $io->note([
                'Wait for completion:',
                "  bin/console ai:batch:wait {$batchId}",
                '  — or —',
                "  bin/console ai:batch:wait {$batchId} --download={$output}",
            ]);
            return 1; // Command::FAILURE — scriptable
        }

        if ($job->isFailed()) {
            $io->error(sprintf('Batch %s failed (status: %s).', $batchId, $job->status));
            return 1;
        }

        if (!$job->isComplete() || $job->outputFileId === null) {
            $io->error('Batch is not complete or has no output file.');
            return 1;
        }

        $lines   = [];
        $ok      = 0;
        $failed  = 0;

        foreach ($this->client->fetchResults($job) as $result) {
            if (!$result->success) {
                $failed++;
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('  FAIL %s: %s', $result->customId, $result->error));
                }
            } else {
                $ok++;
            }
            $lines[] = json_encode($result->raw, JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0));
        }

        $content = implode("\n", $lines) . "\n";

        if ($output !== null) {
            @mkdir(dirname($output), 0775, true);
            file_put_contents($output, $content);
            $io->success(sprintf(
                'Downloaded %d results (%d ok, %d failed) to %s',
                $ok + $failed, $ok, $failed, $output
            ));
            $io->text('Inspect results:');
            $io->text("  cat {$output} | jq '.response.body.choices[0].message.content'");
        } else {
            // stdout — pipe-friendly
            echo $content;
        }

        return $failed > 0 ? 2 : 0;
    }
}
