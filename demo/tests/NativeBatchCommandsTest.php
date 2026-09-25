<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\AdvertisingCommand;
use App\Command\FetchBatchCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Job\JobHandle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NativeBatchCommandsTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;
    private BufferedOutput $output;
    private SymfonyStyle $io;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/native-batch-'.bin2hex(random_bytes(6));
        $this->filesystem = new Filesystem();
        $this->filesystem->dumpFile($this->root.'/data/products.json', json_encode(['products' => [
            ['id' => 7, 'title' => 'Coffee', 'category' => 'food', 'price' => 4.99, 'description' => 'Roasted beans', 'thumbnail' => 'https://example.com/coffee.jpg'],
        ]], JSON_THROW_ON_ERROR));
        $this->output = new BufferedOutput();
        $this->io = new SymfonyStyle(new ArrayInput([]), $this->output);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testSyncUsesTheProjectDataAndNativeResponsesApi(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(self::responseBody(), JSON_THROW_ON_ERROR))]);
        $command = new AdvertisingCommand(Factory::createPlatform('sk-test', $http), $this->filesystem, $this->root);

        self::assertSame(0, $command($this->io));
        self::assertSame(1, $http->getRequestsCount());
        self::assertStringContainsString('product_7: Fuel your next deploy.', $this->output->fetch());
    }

    public function testSubmitPersistsTheNativeHandleForAnotherProcess(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url];
            if (str_ends_with($url, '/v1/files')) {
                return new MockResponse('{"id":"file_input"}');
            }
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('/v1/responses', $body['endpoint']);
            self::assertSame('file_input', $body['input_file_id']);

            return new MockResponse('{"id":"batch_native","endpoint":"/v1/responses","completion_window":"24h"}');
        });
        $command = new AdvertisingCommand(Factory::createPlatform('sk-test', $http), $this->filesystem, $this->root);

        self::assertSame(0, $command($this->io, batch: true));
        self::assertSame([
            ['POST', 'https://api.openai.com/v1/files'],
            ['POST', 'https://api.openai.com/v1/batches'],
        ], $requests);
        $handle = JobHandle::fromString(file_get_contents($this->root.'/var/batches/batch_native.json'));
        self::assertSame('openai_batch', $handle->get('kind'));
        self::assertSame('/v1/responses', $handle->get('endpoint'));
        self::assertSame(86400, $handle->getMaxDuration());
    }

    #[DataProvider('resultCases')]
    public function testFetchPreservesPerRequestOutcomes(string $status, bool $success, bool $error, int $exit): void
    {
        $handleFile = $this->saveHandle();
        $statusBody = json_encode([
            'status' => $status,
            'output_file_id' => $success ? 'file_output' : null,
            'error_file_id' => $error ? 'file_errors' : null,
            'request_counts' => ['total' => (int) $success + (int) $error, 'completed' => (int) $success, 'failed' => (int) $error],
        ], JSON_THROW_ON_ERROR);
        $responses = [new MockResponse($statusBody), new MockResponse($statusBody)];
        if ($success) {
            $responses[] = new MockResponse(json_encode(['custom_id' => 'product_7', 'response' => ['status_code' => 200, 'body' => self::responseBody()]], JSON_THROW_ON_ERROR)."\n");
        }
        if ($error) {
            $responses[] = new MockResponse('{"custom_id":"product_8","error":{"code":"batch_expired","message":"Expired"}}'."\n");
        }
        $http = new MockHttpClient($responses);
        $command = new FetchBatchCommand(Factory::createJobClient('sk-test', $http), $this->filesystem);

        self::assertSame($exit, $command($this->io, $handleFile));
        $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($handleFile.'.results.jsonl', FILE_IGNORE_NEW_LINES));
        self::assertCount((int) $success + (int) $error, $rows);
        if ($success) {
            self::assertSame('product_7', $rows[0]['custom_id']);
            self::assertSame('Fuel your next deploy.', $rows[0]['content']);
        }
        if ($error) {
            self::assertSame('expired', $rows[count($rows) - 1]['status']);
            self::assertSame('batch_expired', $rows[count($rows) - 1]['code']);
        }
    }

    public static function resultCases(): iterable
    {
        yield 'completed' => ['completed', true, false, 0];
        yield 'error file only' => ['completed', false, true, 1];
        yield 'mixed results' => ['completed', true, true, 1];
        yield 'expired partial results' => ['expired', true, true, 1];
        yield 'canceled partial results' => ['cancelled', true, false, 1];
    }

    public function testPendingDoesNotDownloadOrWriteResults(): void
    {
        $file = $this->saveHandle();
        $http = new MockHttpClient([new MockResponse('{"status":"in_progress"}')]);
        $command = new FetchBatchCommand(Factory::createJobClient('sk-test', $http), $this->filesystem);

        self::assertSame(0, $command($this->io, $file));
        self::assertSame(1, $http->getRequestsCount());
        self::assertFileDoesNotExist($file.'.results.jsonl');
    }

    public function testFailedBatchWithoutFilesReturnsFailure(): void
    {
        $file = $this->saveHandle();
        $http = new MockHttpClient([new MockResponse('{"status":"failed"}'), new MockResponse('{"status":"failed"}')]);
        $command = new FetchBatchCommand(Factory::createJobClient('sk-test', $http), $this->filesystem);

        self::assertSame(1, $command($this->io, $file));
        self::assertFileDoesNotExist($file.'.results.jsonl');
    }

    private function saveHandle(): string
    {
        $path = $this->root.'/handle.json';
        $this->filesystem->dumpFile($path, (new JobHandle('batch_native', ['kind' => 'openai_batch', 'endpoint' => '/v1/responses'], 'openai'))->toString());

        return $path;
    }

    private static function responseBody(): array
    {
        return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Fuel your next deploy.']]]]];
    }
}
