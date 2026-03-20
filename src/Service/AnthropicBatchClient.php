<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Tacman\AiBatch\Contract\BatchCapablePlatformInterface;
use Tacman\AiBatch\Model\BatchJob;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Model\BatchResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic Message Batches API client.
 *
 * @see https://docs.anthropic.com/en/api/message-batches
 *
 * Cost: 50% discount vs sync API.
 * Limit: 100,000 requests per batch.
 * Window: 24h completion guarantee.
 */
final class AnthropicBatchClient implements BatchCapablePlatformInterface
{
    private const BASE    = 'https://api.anthropic.com/v1';
    private const VERSION = '2023-06-01';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string              $apiKey,
    ) {}

    public function supportsBatch(): bool { return true; }

    public function submitBatch(array $requests, array $options = []): BatchJob
    {
        $payload = [
            'requests' => array_map(
                static fn(BatchRequest $r) => $r->toAnthropicLine(),
                $requests
            ),
        ];

        $data = $this->post('/messages/batches', $payload);
        return BatchJob::fromAnthropicArray($data);
    }

    public function checkBatch(string $batchId): BatchJob
    {
        $data = $this->get("/messages/batches/{$batchId}");
        return BatchJob::fromAnthropicArray($data);
    }

    public function fetchResults(BatchJob $job): iterable
    {
        if (!$job->isComplete()) {
            throw new \LogicException("Batch {$job->id} is not complete (status: {$job->status})");
        }

        // Anthropic streams results from a paginated endpoint
        $url = self::BASE . "/messages/batches/{$job->id}/results";
        $response = $this->http->request('GET', $url, ['headers' => $this->headers()]);

        foreach (explode("\n", trim($response->getContent())) as $line) {
            if ($line === '') continue;
            try {
                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                yield BatchResult::fromAnthropicLine($data);
            } catch (\JsonException) {}
        }
    }

    public function cancelBatch(string $batchId): BatchJob
    {
        $data = $this->post("/messages/batches/{$batchId}/cancel", []);
        return BatchJob::fromAnthropicArray($data);
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function get(string $path): array
    {
        return $this->http->request('GET', self::BASE . $path, [
            'headers' => $this->headers(),
        ])->toArray();
    }

    private function post(string $path, array $payload): array
    {
        return $this->http->request('POST', self::BASE . $path, [
            'headers' => $this->headers(),
            'json'    => $payload,
        ])->toArray();
    }

    private function headers(): array
    {
        return [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => self::VERSION,
            'anthropic-beta'    => 'message-batches-2024-09-24',
            'content-type'      => 'application/json',
        ];
    }
}
