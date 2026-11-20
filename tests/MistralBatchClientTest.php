<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tacman\AiBatch\Service\MistralBatchClient;

final class MistralBatchClientTest extends TestCase
{
    /** @return list<string> the URLs requested */
    private function urlsFor(?string $baseUri): array
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse(json_encode(['id' => 'job-1', 'status' => 'QUEUED', 'endpoint' => '/v1/ocr']));
        });
        (new MistralBatchClient($http, 'key', $baseUri))->checkBatch('job-1');

        return $urls;
    }

    public function testDefaultsToMistral(): void
    {
        self::assertSame(['https://api.mistral.ai/v1/batch/jobs/job-1'], $this->urlsFor(null));
        self::assertSame(['https://api.mistral.ai/v1/batch/jobs/job-1'], $this->urlsFor(''));
    }

    public function testACompatibleServerCanStandIn(): void
    {
        self::assertSame(['https://mediary.test/v1/batch/jobs/job-1'], $this->urlsFor('https://mediary.test/v1/'));
    }
}
