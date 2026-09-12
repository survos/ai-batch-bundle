<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tacman\AiBatch\Contract\BatchCapablePlatformInterface;
use Tacman\AiBatch\Model\BatchJob;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Model\BatchResult;

/**
 * Mistral Batch API client: /v1/batch/jobs, for any Mistral endpoint -- /v1/ocr as well as
 * /v1/chat/completions.
 *
 * Differences from OpenAI that shape this class:
 *   - `model` and `endpoint` belong to the JOB, not the line, so one submitBatch() is one
 *     (endpoint, model) pair. Mixed requests throw rather than silently use the first model.
 *   - Requests below INLINE_LIMIT go inline in the create call; larger batches are uploaded as a
 *     .jsonl file (purpose=batch) and referenced by input_files.
 *   - Status is upper-case (QUEUED|RUNNING|SUCCESS|FAILED|TIMEOUT_EXCEEDED|
 *     CANCELLATION_REQUESTED|CANCELLED); BatchJob::fromMistralArray() normalizes it.
 *
 * Cost: half the sync price (OCR 4.x: $2 instead of $4 per 1,000 pages).
 * Access: batch jobs need API pay-as-you-go enabled on the organization; without it creation
 * answers 402 "You do not have access to this service" even when listing jobs works.
 *
 * @see https://docs.mistral.ai/capabilities/batch/
 */
final class MistralBatchClient implements BatchCapablePlatformInterface
{
    private const BASE = 'https://api.mistral.ai/v1';

    /** Mistral accepts inline requests below 10,000; above that, upload a file. */
    public const INLINE_LIMIT = 10_000;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
    ) {}

    public function supportsBatch(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param BatchRequest[]       $requests all for one endpoint and one model
     * @param array<string, mixed> $options  metadata (array), timeout_hours (int), inline (bool)
     */
    public function submitBatch(array $requests, array $options = []): BatchJob
    {
        if ($requests === []) {
            throw new \InvalidArgumentException('A Mistral batch needs at least one request.');
        }
        $endpoint = $requests[0]->endpoint ?? '/v1/chat/completions';
        $model    = $requests[0]->model;
        $lines    = [];
        foreach ($requests as $r) {
            if (($r->endpoint ?? '/v1/chat/completions') !== $endpoint || $r->model !== $model) {
                throw new \InvalidArgumentException(sprintf(
                    'A Mistral batch has one endpoint and one model; got %s/%s and %s/%s. Group requests before submitting.',
                    $endpoint, $model, $r->endpoint ?? '/v1/chat/completions', $r->model,
                ));
            }
            $lines[] = $r->toMistralLine();
        }

        $payload = array_filter([
            'endpoint'      => $endpoint,
            'model'         => $model,
            'metadata'      => $options['metadata'] ?? null,
            'timeout_hours' => $options['timeout_hours'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        if (($options['inline'] ?? true) && \count($lines) < self::INLINE_LIMIT) {
            $payload['requests'] = $lines;
        } else {
            $jsonl = implode("\n", array_map(static fn (array $l): string => json_encode($l, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $lines)) . "\n";
            $payload['input_files'] = [$this->uploadInputFile($jsonl)];
        }

        return BatchJob::fromMistralArray($this->request('POST', '/batch/jobs', $payload));
    }

    public function checkBatch(string $batchId): BatchJob
    {
        return BatchJob::fromMistralArray($this->request('GET', "/batch/jobs/{$batchId}"));
    }

    /**
     * Successful lines from output_file, then failed ones from error_file. An inline job may carry
     * its results in `outputs` instead; those are used when there is no output file.
     */
    public function fetchResults(BatchJob $job): iterable
    {
        if (!$job->isTerminal()) {
            throw new \LogicException("Mistral batch {$job->id} is still {$job->status}");
        }

        if ($job->outputFileId !== null) {
            yield from $this->linesOf($job->outputFileId);
        } else {
            foreach ((array) ($job->raw['outputs'] ?? []) as $line) {
                if (is_array($line)) {
                    yield BatchResult::fromMistralLine($line);
                }
            }
        }
        if ($job->errorFileId !== null) {
            yield from $this->linesOf($job->errorFileId);
        }
    }

    public function cancelBatch(string $batchId): BatchJob
    {
        return BatchJob::fromMistralArray($this->request('POST', "/batch/jobs/{$batchId}/cancel"));
    }

    /** @return BatchJob[] newest first */
    public function listBatches(int $limit = 10): array
    {
        $data = $this->request('GET', '/batch/jobs?page_size=' . $limit);

        return array_map(static fn (array $j): BatchJob => BatchJob::fromMistralArray($j), $data['data'] ?? []);
    }

    /** Upload a .jsonl of {custom_id, body} lines; returns the file id. */
    public function uploadInputFile(string $jsonl): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mistral_batch_') . '.jsonl';
        file_put_contents($tmp, $jsonl);
        try {
            $response = $this->http->request('POST', self::BASE . '/files', [
                'auth_bearer' => $this->apiKey,
                'body'        => ['purpose' => 'batch', 'file' => fopen($tmp, 'r')],
            ]);

            return (string) $response->toArray()['id'];
        } finally {
            @unlink($tmp);
        }
    }

    /** @return iterable<BatchResult> */
    private function linesOf(string $fileId): iterable
    {
        $content = $this->http->request('GET', self::BASE . "/files/{$fileId}/content", [
            'auth_bearer' => $this->apiKey,
        ])->getContent();

        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                yield BatchResult::fromMistralLine(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            } catch (\JsonException) {
                // a truncated last line; the ids it held surface as missing results
            }
        }
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $options = ['auth_bearer' => $this->apiKey, 'timeout' => 120];
        if ($json !== null) {
            $options['json'] = $json;
        }

        return $this->http->request($method, self::BASE . $path, $options)->toArray();
    }
}
