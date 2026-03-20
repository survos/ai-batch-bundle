<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Contract;

use Tacman\AiBatch\Model\BatchJob;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Model\BatchResult;

/**
 * Marks an AI platform as capable of async batch processing.
 *
 * PROPOSED for inclusion in symfony/ai as an extension of PlatformInterface.
 *
 * Batch processing advantages over synchronous requests:
 *   - 50% cost reduction (OpenAI, Anthropic)
 *   - Separate, higher rate limit pool
 *   - No timeout pressure — results arrive within 24h
 *   - Natural fit for large-scale enrichment pipelines
 *
 * Provider support:
 *   OpenAI    /v1/batches              ✓ implemented
 *   Anthropic /v1/messages/batches     ✓ implemented
 *   Mistral   (no batch API yet)       ✗ falls back to sync
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
