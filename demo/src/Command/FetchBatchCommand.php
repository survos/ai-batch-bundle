<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\AI\Platform\Bridge\OpenAi\Batch\JobClient;
use Symfony\AI\Platform\Exception\JobFailedException;
use Symfony\AI\Platform\Job\JobHandle;
use Symfony\AI\Platform\Job\JobStateCase;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand('app:fetch-batch', 'Resume a saved Symfony AI JobHandle and fetch batch results')]
final class FetchBatchCommand
{
    public function __construct(
        private readonly JobClient $client,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to the job handle JSON printed by app:ads --batch')] string $handleFile,
        #[Option('Poll until the batch reaches a terminal state')] bool $watch = false,
        #[Option('Save results as JSONL (defaults to the handle filename with .results.jsonl)')] ?string $output = null,
        #[Option('Maximum seconds to watch before returning; the job continues remotely')] int $timeout = 3600,
    ): int {
        if ($timeout < 1) {
            $io->error('The timeout must be positive.');

            return Command::INVALID;
        }
        $handle = JobHandle::fromString(file_get_contents($handleFile));
        if (!$this->client->supports($handle)) {
            $io->error('This handle is not a native OpenAI batch. Old demo database IDs cannot be resumed here.');

            return Command::INVALID;
        }
        $output ??= $handleFile.'.results.jsonl';
        $deadline = microtime(true) + $timeout;
        do {
            $progress = $this->client->getProgress($handle);
            $status = $progress['status'];
            $io->text(sprintf('%s: %s — %d/%d completed, %d failed', $handle->getId(), $status->getRaw(), $progress['completed'], $progress['total'], $progress['failed']));
            if ($status->isTerminal()) {
                break;
            }
            if (!$watch) {
                $io->note('Still processing. Run this command again later, or add --watch.');

                return Command::SUCCESS;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $io->warning('Watch timed out. Keep the handle and fetch again later; the remote batch is still running.');

                return Command::FAILURE;
            }
            usleep((int) (min($handle->getPollInterval() ?? 60.0, $remaining) * 1_000_000));
        } while (true);

        try {
            // The native client also retrieves partial results from canceled/expired batches.
            $result = $this->client->getResult($handle);
        } catch (JobFailedException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $lines = [];
        $failed = !$status->is(JobStateCase::SUCCEEDED);
        foreach ($result->getContent() as $item) {
            $row = ['custom_id' => $item->getId(), 'status' => $item->getCase()->value];
            if ($item->isSuccess()) {
                $row['content'] = $item->getResult()->getContent();
            } else {
                $failed = true;
                $row['error'] = $item->getError();
                $row['code'] = $item->getRaw();
            }
            $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $io->writeln($line);
            $lines[] = $line;
        }
        $this->filesystem->dumpFile($output, implode("\n", $lines).([] === $lines ? '' : "\n"));
        $io->text(sprintf('Saved %d results to %s', count($lines), $output));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
