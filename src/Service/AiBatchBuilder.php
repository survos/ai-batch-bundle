<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Tacman\AiBatch\Contract\BatchCapablePlatformInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Model\BatchRequest;

/**
 * Builds and submits a batch from an iterable of records.
 *
 * Usage:
 *   $batch = $builder->build('fortepan/hu', 'image_enrichment', $records);
 *   $builder->submit($batch);
 *
 * The caller provides a RequestFactory callable that turns a record
 * into a BatchRequest. This keeps the builder agnostic of the task.
 *
 *   $factory = fn(array $row) => new BatchRequest(
 *       customId:     (string)$row['id'],
 *       systemPrompt: $systemPrompt,
 *       userPrompt:   $userPrompt,
 *       model:        'gpt-4o-mini',
 *       imageUrl:     $row['thumbnail_url'],
 *   );
 */
final class AiBatchBuilder
{
    /** Docs say 50k, leave headroom */
    private const MAX_PER_BATCH = 49_000;

    public function __construct(
        private readonly BatchCapablePlatformInterface $client,
    ) {}

    /**
     * Build an AiBatch entity from an iterable of records.
     * Does NOT submit — call submit() separately.
     *
     * @param iterable<array>    $records
     * @param callable(array): BatchRequest $requestFactory
     */
    public function build(
        string   $datasetKey,
        string   $task,
        iterable $records,
        callable $requestFactory,
        array    $meta = [],
    ): AiBatch {
        $batch = new AiBatch();
        $batch->datasetKey = $datasetKey;
        $batch->task       = $task;
        $batch->provider   = 'openai'; // TODO: detect from $client
        $batch->meta       = $meta;

        // Write input JSONL to temp file
        $tmpFile = tempnam(sys_get_temp_dir(), 'ai_batch_') . '.jsonl';
        $handle  = fopen($tmpFile, 'w');
        $count   = 0;

        foreach ($records as $record) {
            if ($count >= self::MAX_PER_BATCH) {
                break;
            }
            $request = $requestFactory($record);
            fwrite($handle, json_encode($request->toOpenAiLine(), JSON_THROW_ON_ERROR) . "\n");
            $count++;
        }

        fclose($handle);

        $batch->inputFilePath = $tmpFile;
        $batch->requestCount  = $count;

        return $batch;
    }

    /**
     * Upload the input file and create the batch on the provider.
     * Updates $batch in place with providerBatchId and status.
     */
    public function submit(AiBatch $batch, array $options = []): AiBatch
    {
        if (!is_file($batch->inputFilePath)) {
            throw new \RuntimeException("Input file not found: {$batch->inputFilePath}");
        }

        // Read requests back from JSONL for submission
        // (AiBatchBuilder stores them as JSONL lines, not BatchRequest objects)
        $content = file_get_contents($batch->inputFilePath);
        $job = $this->client->submitBatch(
            $this->parseJsonlRequests($content),
            $options
        );

        $batch->markSubmitted($job->id, $job->inputFileId ?? '');

        // Clean up temp file after submission
        @unlink($batch->inputFilePath);
        $batch->inputFilePath = null;

        return $batch;
    }

    /**
     * Download the provider output JSONL and save it under APP_DATA_DIR/ai-batches/.
     *
     * The file is named by the provider batch ID (e.g. batch_abc123.jsonl) so it
     * survives DB resets and can be replayed with batch:replay.
     *
     * Sets $batch->savedResultPath on success.
     *
     * @param string $savePath  Full path to write — use DataPaths::aiBatchResultFile($datasetKey, $batch->providerBatchId)
     */
    public function downloadAndSave(AiBatch $batch, string $savePath): void
    {
        if ($batch->providerBatchId === null) {
            throw new \RuntimeException('Batch has no providerBatchId — was it submitted?');
        }
        if ($batch->outputFileId === null) {
            throw new \RuntimeException("Batch {$batch->providerBatchId} has no outputFileId yet — is it complete?");
        }

        $dir = dirname($savePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }

        $job     = $this->client->checkBatch($batch->providerBatchId);
        $results = $this->client->fetchResults($job);

        $handle = fopen($savePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$savePath} for writing.");
        }

        foreach ($results as $result) {
            fwrite($handle, json_encode($result->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        fclose($handle);

        $batch->savedResultPath = $savePath;
    }

    /**
     * Stream results from a saved JSONL file, yielding BatchResult objects.
     * Use this to replay results without hitting the provider again.
     *
     * @return iterable<\Tacman\AiBatch\Model\BatchResult>
     */
    public function streamSavedResults(string $savedPath): iterable
    {
        if (!is_file($savedPath)) {
            throw new \RuntimeException("Saved result file not found: {$savedPath}");
        }

        $handle = fopen($savedPath, 'rb');
        while (!feof($handle)) {
            $line = trim((string) fgets($handle));
            if ($line === '') {
                continue;
            }
            $raw = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            yield \Tacman\AiBatch\Model\BatchResult::fromOpenAiLine($raw);
        }
        fclose($handle);
    }

    /** @return BatchRequest[] */
    private function parseJsonlRequests(string $jsonl): array
    {
        $requests = [];
        foreach (explode("\n", trim($jsonl)) as $line) {
            if ($line === '') continue;
            $data     = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $body     = $data['body'];
            $messages = $body['messages'];

            $system = '';
            $user   = '';
            $image  = null;
            foreach ($messages as $m) {
                if ($m['role'] === 'system') {
                    $system = $m['content'];
                } elseif ($m['role'] === 'user') {
                    if (is_array($m['content'])) {
                        foreach ($m['content'] as $part) {
                            if ($part['type'] === 'text')      $user  = $part['text'];
                            if ($part['type'] === 'image_url') $image = $part['image_url']['url'];
                        }
                    } else {
                        $user = $m['content'];
                    }
                }
            }

            $requests[] = new BatchRequest(
                customId:     $data['custom_id'],
                systemPrompt: $system,
                userPrompt:   $user,
                model:        $body['model'],
                imageUrl:     $image,
            );
        }
        return $requests;
    }
}
