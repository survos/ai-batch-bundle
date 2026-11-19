<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\AI\Platform\Job\JobClientInterface;
use Symfony\AI\Platform\Job\JobHandle;
use Symfony\AI\Platform\Job\JobStateCase;
use Symfony\AI\Platform\Job\JobStatus;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\BatchItem;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Tacman\AiBatch\Entity\AiBatch;

/**
 * Durable orchestration for native Symfony AI batches. The caller owns persist()/flush()
 * and idempotent application of results to its subjects. Existing raw-provider jobs use
 * the legacy clients; a native handle must never be reconstructed from a legacy batch ID.
 */
class NativeBatchService
{
    public function __construct(
        private readonly PlatformInterface $platform,
        protected readonly JobClientInterface $client,
        private readonly Filesystem $filesystem,
    ) {
    }

    /** @param array<string, mixed> $inputs Stable subject IDs, also returned on each result. */
    public function submit(AiBatch $batch, string $model, array $inputs, array $options = []): JobHandle
    {
        if (!$batch->isBuilding() || null !== $batch->providerBatchId || null !== $batch->getJobHandle()) {
            throw new \LogicException('This batch already has a job; resume it instead of submitting it again.');
        }
        $handle = $this->platform->invoke($model, $inputs, ['batch' => true] + $options)->asJob();
        $batch->attachJobHandle($handle);
        $batch->requestCount = count($inputs);

        return $handle;
    }

    public function refresh(AiBatch $batch): JobStatus
    {
        $status = $this->client->getStatus($this->handle($batch));
        $batch->applyProviderStatus(match ($status->getCase()) {
            JobStateCase::QUEUED, JobStateCase::RUNNING => 'in_progress',
            JobStateCase::SUCCEEDED => 'completed',
            JobStateCase::FAILED, JobStateCase::CANCELED, JobStateCase::EXPIRED => 'failed',
            default => 'unknown',
        }, $batch->completedCount, $batch->failedCount, null, null);
        $batch->meta['symfony_ai_status'] = $status->jsonSerialize();

        return $status;
    }

    /** Includes partial results and errors from canceled or expired batches. */
    public function results(AiBatch $batch): iterable
    {
        return $this->client->getResult($this->handle($batch))->getContent();
    }

    /**
     * Archive normalized outcomes, not provider-specific HTTP response bodies. Atomic replacement
     * ensures an interrupted download never becomes the replay source. No DB flag is set until done.
     */
    public function archive(AiBatch $batch, string $path): void
    {
        $this->filesystem->mkdir(dirname($path));
        $temporaryDirectory = dirname($path).'/.native-batch-'.bin2hex(random_bytes(8));
        $temporary = $temporaryDirectory.'/results.jsonl';
        $writer = JsonlWriter::open($temporary, compressionLevel: 0);
        try {
            foreach ($this->results($batch) as $item) {
                $writer->write(self::archiveItem($item));
            }
            $writer->finish();
            $this->filesystem->rename($temporary, $path, true);
            $batch->savedResultPath = $path;
        } finally {
            $writer->close();
            $this->filesystem->remove($temporaryDirectory);
        }
    }

    /** @return iterable<array<string, mixed>> Replay without an API request or live provider client. */
    public static function replay(string $path): iterable
    {
        foreach (JsonlReader::open($path) as $row) {
            if (($row['format'] ?? null) !== 'symfony-ai-batch-v1') {
                throw new \UnexpectedValueException('Not a native batch archive; use the legacy replay service for provider JSONL.');
            }
            yield $row;
        }
    }

    protected function handle(AiBatch $batch): JobHandle
    {
        $handle = $batch->getJobHandle();
        if (null === $handle || !$this->client->supports($handle)) {
            throw new \LogicException('No supported native handle is stored on this batch. Use the legacy client for legacy jobs.');
        }

        return $handle;
    }

    private static function archiveItem(BatchItem $item): array
    {
        return [
            'format' => 'symfony-ai-batch-v1',
            'custom_id' => $item->getId(),
            'status' => $item->getCase()->value,
            'raw_status' => $item->getRaw(),
            'content' => $item->isSuccess() ? (new Serializer([new ObjectNormalizer()]))->normalize($item->getResult()->getContent(), 'json') : null,
            'error' => $item->getError(),
        ];
    }
}
