<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/**
 * Upload a JSONL file to OpenAI and create a batch.
 *
 *   bin/console ai:batch:create var/batch/products.jsonl
 *   bin/console ai:batch:create var/batch/products.jsonl --endpoint=/v1/responses
 */
#[AsCommand('ai:batch:create', 'Upload a JSONL file and create an OpenAI batch job')]
final class BatchCreateCommand
{
    public function __construct(
        private readonly OpenAiBatchClient $client,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to input JSONL file')] string $file,
        #[Option('API endpoint for all requests in the batch')] string $endpoint = '/v1/chat/completions',
        #[Option('Completion window (24h only currently)')] string $window = '24h',
        #[Option('Optional description stored with the batch')] ?string $description = null,
    ): int {
        if (!is_file($file)) {
            $io->error("File not found: {$file}");
            return 1;
        }

        $lines = count(file($file, FILE_SKIP_EMPTY_LINES));
        $size  = number_format(filesize($file) / 1024, 1);
        $io->text(sprintf('Input: %s (%d requests, %s KB)', $file, $lines, $size));

        $io->text('Uploading input file...');
        $content = file_get_contents($file);
        $fileId  = $this->client->uploadInputFile($content);
        $io->text("Input file uploaded: <info>{$fileId}</info>");

        $options = ['completion_window' => $window, 'endpoint' => $endpoint];
        if ($description !== null) {
            $options['metadata'] = ['description' => $description];
        }

        $io->text('Creating batch...');
        $job = $this->client->submitFromFileId($fileId, $options);

        $io->success(sprintf('Batch created: %s', $job->id));
        $io->table(
            ['Field', 'Value'],
            [
                ['Batch ID',  $job->id],
                ['Status',    $job->status],
                ['Requests',  $job->totalCount],
                ['Endpoint',  $endpoint],
            ]
        );

        $io->note([
            'Check status:',
            "  bin/console ai:batch:status {$job->id}",
            '',
            'Wait for completion:',
            "  bin/console ai:batch:wait {$job->id}",
        ]);

        return 0;
    }
}
