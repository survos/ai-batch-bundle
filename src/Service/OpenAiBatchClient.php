<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Tacman\AiBatch\Contract\BatchCapablePlatformInterface;
use Tacman\AiBatch\Model\BatchJob;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Model\BatchResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI Batch API client.
 *
 * Implements the proposed BatchCapablePlatformInterface.
 * Can be used standalone or as a decorator for OpenAiPlatform.
 *
 * Cost: 50% discount vs sync API.
 * Limit: 50,000 requests / 200MB per batch.
 * Window: 24h completion guarantee.
 */
final class OpenAiBatchClient implements BatchCapablePlatformInterface
{
    private const BASE = 'https://api.openai.com/v1';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string              $apiKey,
    ) {}

    public function supportsBatch(): bool { return true; }

    public function submitBatch(array $requests, array $options = []): BatchJob
    {
        // 1. Build JSONL content
        $lines = array_map(
            static fn(BatchRequest $r) => json_encode($r->toOpenAiLine(), JSON_THROW_ON_ERROR),
            $requests
        );
        $jsonl = implode("\n", $lines);

        // 2. Upload input file
        $fileId = $this->uploadFile($jsonl, 'batch');

        // 3. Create batch
        $payload = array_merge([
            'input_file_id'     => $fileId,
            'endpoint'          => '/v1/chat/completions',
            'completion_window' => '24h',
        ], $options);

        $data = $this->post('/batches', $payload);
        return BatchJob::fromOpenAiArray($data);
    }

    public function checkBatch(string $batchId): BatchJob
    {
        $data = $this->get("/batches/{$batchId}");
        return BatchJob::fromOpenAiArray($data);
    }

    public function fetchResults(BatchJob $job): iterable
    {
        if (!$job->isComplete()) {
            throw new \LogicException("Batch {$job->id} is not complete (status: {$job->status})");
        }
        if ($job->outputFileId === null) {
            return;
        }

        $content = $this->getFileContent($job->outputFileId);
        foreach (explode("\n", trim($content)) as $line) {
            if ($line === '') continue;
            try {
                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                yield BatchResult::fromOpenAiLine($data);
            } catch (\JsonException) {
                // skip malformed lines
            }
        }
    }

    public function cancelBatch(string $batchId): BatchJob
    {
        $data = $this->post("/batches/{$batchId}/cancel", []);
        return BatchJob::fromOpenAiArray($data);
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function uploadFile(string $content, string $purpose): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'aibatch_upload_') . '.jsonl';
        file_put_contents($tmpFile, $content);

        $response = $this->http->request('POST', self::BASE . '/files', [
            'auth_bearer' => $this->apiKey,
            'body'        => [
                'purpose' => $purpose,
                'file'    => fopen($tmpFile, 'r'),
            ],
        ]);

        @unlink($tmpFile);
        return $response->toArray()['id'];
    }

    /** Public: upload a JSONL string, return the file ID. */
    public function uploadInputFile(string $content): string
    {
        return $this->uploadFile($content, 'batch');
    }

    /** Public: create a batch from an already-uploaded file ID. */
    public function submitFromFileId(string $fileId, array $options = []): BatchJob
    {
        $payload = array_merge([
            'input_file_id'     => $fileId,
            'endpoint'          => $options['endpoint'] ?? '/v1/chat/completions',
            'completion_window' => $options['completion_window'] ?? '24h',
        ], array_diff_key($options, ['endpoint' => 1, 'completion_window' => 1]));

        $data = $this->post('/batches', $payload);
        return BatchJob::fromOpenAiArray($data);
    }

    /**
     * List recent batches on the account.
     * @return BatchJob[]
     */
    public function listBatches(int $limit = 10): array
    {
        $data = $this->get("/batches?limit={$limit}");
        return array_map(
            static fn(array $b) => BatchJob::fromOpenAiArray($b),
            $data['data'] ?? []
        );
    }

    private function getFileContent(string $fileId): string
    {
        $response = $this->http->request('GET', self::BASE . "/files/{$fileId}/content", [
            'auth_bearer' => $this->apiKey,
        ]);
        return $response->getContent();
    }

    private function get(string $path): array
    {
        return $this->http->request('GET', self::BASE . $path, [
            'auth_bearer' => $this->apiKey,
        ])->toArray();
    }

    private function post(string $path, array $payload): array
    {
        return $this->http->request('POST', self::BASE . $path, [
            'auth_bearer' => $this->apiKey,
            'json'        => $payload,
        ])->toArray();
    }
}
