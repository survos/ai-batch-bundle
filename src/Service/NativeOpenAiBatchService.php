<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Symfony\AI\Platform\Bridge\OpenAi\Batch\JobClient;
use Symfony\AI\Platform\Job\JobHandle;
use Symfony\AI\Platform\Job\JobStatus;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\BatchItem;
use Symfony\Component\Filesystem\Filesystem;
use Tacman\AiBatch\Entity\AiBatch;

/**
 * Durable orchestration for native Symfony AI batches. The caller owns persist()/flush()
 * and idempotent application of results to its subjects. Existing raw-provider jobs use
 * the legacy clients; a native handle must never be reconstructed from a legacy batch ID.
 */
final class NativeOpenAiBatchService
{
    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly JobClient $client,
        private readonly Filesystem $filesystem,
    ) {
    }

    /** @param array<string, MessageBag> $inputs Stable subject IDs, also returned on each result. */
    public function submit(AiBatch $batch, string $model, array $inputs, array $options = []): JobHandle
    {
        if (!$batch->isBuilding() || $batch->providerBatchId !== null || $batch->getJobHandle() !== null) {
            throw new \LogicException('This batch already has a job; resume it instead of submitting it again.');
        }
        $handle = $this->platform->invoke($model, $inputs, ['batch' => true] + $options)->asJob();
        $batch->attachJobHandle($handle);
        $batch->requestCount = count($inputs);

        return $handle;
    }

    public function refresh(AiBatch $batch): JobStatus
    {
        $progress = $this->client->getProgress($this->handle($batch));
        $batch->applyProviderStatus($progress['status']->getRaw(), $progress['completed'], $progress['failed'], null, null);
        $batch->meta['symfony_ai_status'] = $progress['status']->jsonSerialize();

        return $progress['status'];
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
        $temporary = tempnam(dirname($path), '.native-batch-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create the batch archive.');
        }
        $stream = fopen($temporary, 'wb');
        if ($stream === false) {
            $this->filesystem->remove($temporary);
            throw new \RuntimeException('Cannot open the batch archive.');
        }
        try {
            foreach ($this->results($batch) as $item) {
                $line = json_encode(self::archiveItem($item), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                if (fwrite($stream, $line) !== strlen($line)) {
                    throw new \RuntimeException('Cannot write the batch archive.');
                }
            }
            fclose($stream);
            $stream = null;
            $this->filesystem->rename($temporary, $path, true);
            $batch->savedResultPath = $path;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $this->filesystem->remove($temporary);
        }
    }

    /** @return iterable<array<string, mixed>> Replay without an API request or live provider client. */
    public static function replay(string $path): iterable
    {
        $file = new \SplFileObject($path, 'r');
        foreach ($file as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (($row['format'] ?? null) !== 'symfony-ai-batch-v1') {
                throw new \UnexpectedValueException('Not a native batch archive; use the legacy replay service for provider JSONL.');
            }
            yield $row;
        }
    }

    private function handle(AiBatch $batch): JobHandle
    {
        $handle = $batch->getJobHandle();
        if ($handle === null || !$this->client->supports($handle)) {
            throw new \LogicException('No native OpenAI handle is stored on this batch. Use the legacy client for legacy jobs.');
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
            'content' => $item->isSuccess() ? $item->getResult()->getContent() : null,
            'error' => $item->getError(),
        ];
    }
}
