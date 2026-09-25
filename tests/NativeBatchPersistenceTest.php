<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Service\NativeOpenAiBatchService;

final class NativeBatchPersistenceTest extends TestCase
{
    public function testPersistedHandleResumesAndArchivesInAnotherProcess(): void
    {
        $submitHttp = new MockHttpClient([
            new MockResponse('{"id":"file_input"}'),
            new MockResponse('{"id":"batch_native","endpoint":"/v1/responses","completion_window":"24h"}'),
        ]);
        $batch = new AiBatch();
        $batch->meta = ['subject_ids' => ['subject_1']];
        $this->service($submitHttp)->submit($batch, 'gpt-4o-mini', ['subject_1' => new MessageBag(Message::ofUser('Hello'))]);
        self::assertSame('submitted', $batch->status);
        self::assertSame(1, $batch->requestCount);
        self::assertSame(['subject_1'], $batch->meta['subject_ids']);

        // The existing Doctrine JSON column is the durable boundary, with no PHP objects.
        $restored = new AiBatch();
        $restored->providerBatchId = $batch->providerBatchId;
        $restored->meta = json_decode(json_encode($batch->meta, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($batch->getJobHandle()->toArray(), $restored->getJobHandle()->toArray());
        $http = new MockHttpClient([
            new MockResponse('{"status":"expired","request_counts":{"total":2,"completed":1,"failed":1}}'),
            new MockResponse('{"status":"expired","output_file_id":"output","error_file_id":"errors"}'),
            new MockResponse('{"custom_id":"subject_1","response":{"status_code":200,"body":{"output":[{"type":"message","content":[{"type":"output_text","text":"Saved answer"}]}]}}}'."\n"),
            new MockResponse('{"custom_id":"subject_2","error":{"code":"batch_expired","message":"Expired"}}'."\n"),
        ]);
        $service = $this->service($http);
        $service->refresh($restored);
        self::assertTrue($restored->isFailed());
        self::assertSame(1, $restored->completedCount);
        self::assertSame(1, $restored->failedCount);
        $path = sys_get_temp_dir().'/native-archive-'.bin2hex(random_bytes(6)).'/results.jsonl';
        try {
            $service->archive($restored, $path);
            self::assertSame($path, $restored->savedResultPath);
            $rows = iterator_to_array(NativeOpenAiBatchService::replay($path));
            self::assertCount(2, $rows);
            self::assertSame('Saved answer', $rows[0]['content']);
            self::assertSame('subject_1', $rows[0]['custom_id']);
            self::assertSame('expired', $rows[1]['status']);
            self::assertSame('batch_expired', $rows[1]['raw_status']);
            self::assertSame(4, $http->getRequestsCount());
        } finally {
            (new Filesystem())->remove(dirname($path));
        }
    }

    public function testLegacyBatchIsNotGuessedIntoANativeHandle(): void
    {
        $batch = new AiBatch();
        $batch->providerBatchId = 'legacy_job';
        self::assertNull($batch->getJobHandle());
        $this->expectException(\LogicException::class);
        $this->service(new MockHttpClient())->refresh($batch);
    }

    public function testSubmittedBatchCannotBeSubmittedTwice(): void
    {
        $batch = new AiBatch();
        $batch->markSubmitted('already_submitted', 'file_input');
        $this->expectException(\LogicException::class);
        $this->service(new MockHttpClient())->submit($batch, 'gpt-4o-mini', []);
    }

    public function testArchiveFailurePreservesThePreviousArchive(): void
    {
        $batch = new AiBatch();
        $batch->attachJobHandle(new \Symfony\AI\Platform\Job\JobHandle('batch_native', ['kind' => 'openai_batch']));
        $http = new MockHttpClient([
            new MockResponse('{"status":"completed","output_file_id":"output"}'),
            new MockResponse('invalid JSON'),
        ]);
        $path = sys_get_temp_dir().'/native-archive-'.bin2hex(random_bytes(6)).'/results.jsonl';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($path, 'previous complete archive');
        try {
            try {
                $this->service($http)->archive($batch, $path);
                self::fail('Invalid provider JSON must abort the archive.');
            } catch (\Symfony\AI\Platform\Exception\RuntimeException) {
                self::assertSame('previous complete archive', file_get_contents($path));
                self::assertNull($batch->savedResultPath);
                self::assertSame([], glob(dirname($path).'/.native-batch-*'));
            }
        } finally {
            $filesystem->remove(dirname($path));
        }
    }

    private function service(MockHttpClient $http): NativeOpenAiBatchService
    {
        return new NativeOpenAiBatchService(Factory::createPlatform('sk-test', $http), Factory::createJobClient('sk-test', $http), new Filesystem());
    }
}
