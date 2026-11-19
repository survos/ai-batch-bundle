<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Service;

use Symfony\AI\Platform\Bridge\OpenAi\Batch\JobClient;
use Symfony\AI\Platform\Job\JobStatus;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Filesystem\Filesystem;
use Tacman\AiBatch\Entity\AiBatch;

final class NativeOpenAiBatchService extends NativeBatchService
{
    private readonly JobClient $openAiClient;

    public function __construct(PlatformInterface $platform, JobClient $client, Filesystem $filesystem)
    {
        parent::__construct($platform, $client, $filesystem);
        $this->openAiClient = $client;
    }

    public function refresh(AiBatch $batch): JobStatus
    {
        $progress = $this->openAiClient->getProgress($this->handle($batch));
        $batch->applyProviderStatus($progress['status']->getRaw(), $progress['completed'], $progress['failed'], null, null);
        $batch->meta['symfony_ai_status'] = $progress['status']->jsonSerialize();

        return $progress['status'];
    }
}
