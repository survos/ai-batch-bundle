<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Model;

/**
 * Represents a batch job on the provider side.
 * Immutable snapshot — create a new instance on each status check.
 */
final class BatchJob
{
    public function __construct(
        /** Provider-assigned batch ID, e.g. "batch_abc123" */
        public readonly string  $id,
        /** One of: validating|in_progress|finalizing|completed|failed|expired|cancelled */
        public readonly string  $status,
        public readonly string  $provider,
        /** File ID of the input JSONL (provider-side) */
        public readonly ?string $inputFileId    = null,
        /** File ID of the output JSONL — set when completed */
        public readonly ?string $outputFileId   = null,
        /** File ID of the error JSONL — set when some requests failed */
        public readonly ?string $errorFileId    = null,
        public readonly int     $totalCount     = 0,
        public readonly int     $completedCount = 0,
        public readonly int     $failedCount    = 0,
        public readonly ?int    $createdAt      = null,
        public readonly ?int    $completedAt    = null,
        public readonly ?int    $expiresAt      = null,
        /** Provider-specific raw response for debugging */
        public readonly array   $raw            = [],
    ) {}

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'expired', 'cancelled'], true);
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['validating', 'in_progress', 'finalizing'], true);
    }

    public function isTerminal(): bool
    {
        return $this->isComplete() || $this->isFailed();
    }

    public static function fromOpenAiArray(array $data): self
    {
        return new self(
            id:             $data['id'],
            status:         $data['status'],
            provider:       'openai',
            inputFileId:    $data['input_file_id']  ?? null,
            outputFileId:   $data['output_file_id'] ?? null,
            errorFileId:    $data['error_file_id']  ?? null,
            totalCount:     $data['request_counts']['total']     ?? 0,
            completedCount: $data['request_counts']['completed'] ?? 0,
            failedCount:    $data['request_counts']['failed']    ?? 0,
            createdAt:      $data['created_at']   ?? null,
            completedAt:    $data['completed_at'] ?? null,
            expiresAt:      $data['expires_at']   ?? null,
            raw:            $data,
        );
    }

    public static function fromAnthropicArray(array $data): self
    {
        return new self(
            id:             $data['id'],
            status:         self::normalizeAnthropicStatus($data['processing_status']),
            provider:       'anthropic',
            totalCount:     $data['request_counts']['processing']
                          + $data['request_counts']['succeeded']
                          + $data['request_counts']['errored']
                          + $data['request_counts']['canceled']
                          + $data['request_counts']['expired'],
            completedCount: $data['request_counts']['succeeded']  ?? 0,
            failedCount:    ($data['request_counts']['errored'] ?? 0)
                          + ($data['request_counts']['canceled'] ?? 0)
                          + ($data['request_counts']['expired'] ?? 0),
            createdAt:      isset($data['created_at']) ? strtotime($data['created_at']) : null,
            raw:            $data,
        );
    }

    /**
     * A Mistral /v1/batch/jobs object. Status is normalized to the OpenAI vocabulary the rest of
     * the bundle speaks (AiBatch::applyProviderStatus). SUCCESS means the job ran to the end, not
     * that every line succeeded: failed lines are in error_file and counted in failed_requests.
     */
    public static function fromMistralArray(array $data): self
    {
        return new self(
            id:             (string) $data['id'],
            status:         match ((string) ($data['status'] ?? '')) {
                'QUEUED'                                => 'validating',
                'RUNNING', 'CANCELLATION_REQUESTED'     => 'in_progress',
                'SUCCESS'                               => 'completed',
                'FAILED'                                => 'failed',
                'TIMEOUT_EXCEEDED'                      => 'expired',
                'CANCELLED'                             => 'cancelled',
                default                                 => strtolower((string) ($data['status'] ?? 'unknown')),
            },
            provider:       'mistral',
            inputFileId:    $data['input_files'][0] ?? null,
            outputFileId:   $data['output_file'] ?? null,
            errorFileId:    $data['error_file'] ?? null,
            totalCount:     (int) ($data['total_requests'] ?? 0),
            completedCount: (int) ($data['succeeded_requests'] ?? $data['completed_requests'] ?? 0),
            failedCount:    (int) ($data['failed_requests'] ?? 0),
            createdAt:      self::epoch($data['created_at'] ?? null),
            completedAt:    self::epoch($data['completed_at'] ?? null),
            raw:            $data,
        );
    }

    /** Mistral has returned both epoch seconds and ISO strings across API versions. */
    private static function epoch(mixed $value): ?int
    {
        return match (true) {
            is_int($value)                      => $value,
            is_string($value) && $value !== ''  => strtotime($value) ?: null,
            default                             => null,
        };
    }

    private static function normalizeAnthropicStatus(string $status): string
    {
        return match ($status) {
            'in_progress' => 'in_progress',
            'ended'       => 'completed',
            default       => $status,
        };
    }
}
