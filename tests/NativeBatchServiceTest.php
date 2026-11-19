<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Job\JobClientInterface;
use Symfony\AI\Platform\Job\JobHandle;
use Symfony\AI\Platform\Job\JobStateCase;
use Symfony\AI\Platform\Job\JobStatus;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\BatchItem;
use Symfony\AI\Platform\Result\BatchResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Component\Filesystem\Filesystem;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Service\NativeBatchService;

final class NativeBatchServiceTest extends TestCase
{
    public function testProviderStatusAndStructuredArchive(): void
    {
        $client = new class implements JobClientInterface {
            public function supports(JobHandle $handle): bool
            {
                return 'test_batch' === $handle->get('kind');
            }

            public function getStatus(JobHandle $handle): JobStatus
            {
                return new JobStatus(JobStateCase::SUCCEEDED, 'SUCCESS');
            }

            public function getResult(JobHandle $handle): BatchResult
            {
                $ocr = new class {
                    public function getMarkdown(): string
                    {
                        return 'Original OCR';
                    }

                    public function getBox(): array
                    {
                        return [1.25, 2.5, 3.75, 4.0];
                    }
                };

                return new BatchResult((static function () use ($ocr) {
                    yield BatchItem::succeeded('page-1', new ObjectResult($ocr));
                    yield BatchItem::errored('page-2', 'Failed fetch');
                })());
            }
        };
        $batch = new AiBatch();
        $batch->attachJobHandle(new JobHandle('job', ['kind' => 'test_batch'], 'test'));
        $service = new NativeBatchService($this->createStub(PlatformInterface::class), $client, new Filesystem());
        $service->refresh($batch);
        self::assertSame('completed', $batch->status);
        self::assertSame('SUCCESS', $batch->meta['symfony_ai_status']['raw']);
        $dir = sys_get_temp_dir().'/batch-test-'.bin2hex(random_bytes(6));
        $path = $dir.'/archive.jsonl';
        try {
            $service->archive($batch, $path);
            $rows = iterator_to_array($service::replay($path));
            self::assertSame('Original OCR', $rows[0]['content']['markdown']);
            self::assertEquals([1.25, 2.5, 3.75, 4.0], $rows[0]['content']['box']);
            self::assertSame('errored', $rows[1]['status']);
            self::assertSame('Failed fetch', $rows[1]['error']);
        } finally {
            (new Filesystem())->remove($dir);
        }
    }
}
