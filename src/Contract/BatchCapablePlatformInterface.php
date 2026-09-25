<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Contract;

use Tacman\AiBatch\Model\BatchJob;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Model\BatchResult;

/**
 * Marks an AI platform as capable of async batch processing.
 *
 * Legacy provider adapter contract used by existing application pipelines.
 * New OpenAI jobs use NativeOpenAiBatchService and Symfony AI 0.14 JobHandle instead.
 *
 * Batch processing advantages over synchronous requests:
 *   - 50% cost reduction (OpenAI, Anthropic, Mistral)
 *   - Separate, higher rate limit pool
 *   - No timeout pressure — results arrive within 24h
 *   - Natural fit for large-scale enrichment pipelines
 *
 * Provider support (BatchClients resolves one by name):
 *   OpenAI    /v1/batches              ✓ implemented
 *   Anthropic /v1/messages/batches     ✓ implemented
 *   Mistral   /v1/batch/jobs           ✓ implemented (any endpoint, incl. /v1/ocr)
 *   Google    Vertex AI batch predict  planned
 *
 * The Symfony Scheduler polls checkBatch() at a configured interval
 * (e.g. every 2 minutes) until the job completes.
 *
 * @see https://platform.openai.com/docs/guides/batch
 * @see https://docs.anthropic.com/en/api/message-batches
 */
interface BatchCapablePlatformInterface
{
    /**
     * Runtime capability check — avoids instanceof in calling code.
     */
    public function supportsBatch(): bool;

    /**
     * Upload requests and create a batch job on the provider.
     *
     * Each BatchRequest carries a customId that is echoed in results,
     * allowing correct mapping regardless of output ordering.
     *
     * @param BatchRequest[]       $requests  Up to 50,000 per batch (OpenAI)
     * @param array<string, mixed> $options   e.g. ['completion_window' => '24h']
     */
    public function submitBatch(array $requests, array $options = []): BatchJob;

    /**
     * Retrieve current status of a previously submitted batch.
     *
     * Called by the Scheduler task. Returns the same BatchJob
     * with updated status, counts, and file IDs when complete.
     */
    public function checkBatch(string $batchId): BatchJob;

    /**
     * Download and yield results for a completed batch.
     *
     * @return iterable<BatchResult>  One per request, keyed by customId
     * @throws \LogicException if BatchJob is not in completed state
     */
    public function fetchResults(BatchJob $job): iterable;

    /**
     * Cancel an in-progress batch.
     * Already-completed requests within the batch are not refunded.
     */
    public function cancelBatch(string $batchId): BatchJob;
}
