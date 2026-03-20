<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/**
 * Poll until a batch completes, optionally downloading results.
 *
 *   bin/console ai:batch:wait batch_abc123
 *   bin/console ai:batch:wait batch_abc123 --download=results.jsonl
 *   bin/console ai:batch:wait batch_abc123 --interval=60
 */
#[AsCommand('ai:batch:wait', 'Poll until an OpenAI batch completes')]
final class BatchWaitCommand
{
    public function __construct(
        private readonly OpenAiBatchClient $client,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('OpenAI batch ID')] string $batchId,
        #[Option('Auto-download to this file when complete')] ?string $download = null,
        #[Option('Poll interval in seconds')] int $interval = 30,
    ): int {
        $io->text(sprintf('Watching batch <info>%s</info> (polling every %ds)...', $batchId, $interval));
        $io->newLine();

        $start = time();

        while (true) {
            $job     = $this->client->checkBatch($batchId);
            $elapsed = gmdate('H:i:s', time() - $start);

            $bar = $job->totalCount > 0
                ? sprintf('%d/%d (%.0f%%)', $job->completedCount, $job->totalCount, $job->completedCount / $job->totalCount * 100)
                : '...';

            $io->write(sprintf("\r  ⏳ [%s] %s — %s    ", $elapsed, $job->status, $bar));

            if ($job->isComplete()) {
                $io->newLine(2);
                $io->success(sprintf(
                    'Batch complete! %d succeeded, %d failed.',
                    $job->completedCount, $job->failedCount
                ));

                if ($download !== null) {
                    $io->text("Downloading to {$download}...");
                    @mkdir(dirname($download), 0775, true);
                    $content = '';
                    foreach ($this->client->fetchResults($job) as $result) {
                        $content .= json_encode($result->raw, JSON_UNESCAPED_UNICODE) . "\n";
                    }
                    file_put_contents($download, $content);
                    $io->success("Results saved to {$download}");
                    $io->text("  cat {$download} | jq '.response.body.choices[0].message.content'");
                } else {
                    $io->note([
                        'Download results:',
                        "  bin/console ai:batch:download {$batchId} --output=results.jsonl",
                    ]);
                }
                return 0;
            }

            if ($job->isFailed()) {
                $io->newLine(2);
                $io->error(sprintf('Batch %s (status: %s).', $batchId, $job->status));
                return 1;
            }

            sleep($interval);
        }
    }
}
