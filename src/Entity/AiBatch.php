<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Persists an AI batch job through its lifecycle.
 *
 * Workflow places:
 *   building → submitted → processing → completed
 *                                     ↘ failed | expired
 */
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/ai-batches/{id}'),
        new GetCollection(uriTemplate: '/ai-batches'),
    ],
    normalizationContext: ['groups' => ['ai_batch:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'provider' => 'exact', 'task' => 'exact', 'datasetKey' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'submittedAt', 'completedAt', 'requestCount'])]
#[ORM\Entity]
#[ORM\Table(name: 'ai_batch')]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['provider', 'status'])]
class AiBatch
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['ai_batch:read'])]
    public ?int $id = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['ai_batch:read'])]
    #[ApiProperty(description: 'Provider batch ID e.g. batch_abc123')]
    public ?string $providerBatchId = null;

    #[ORM\Column(length: 32)]
    #[Groups(['ai_batch:read'])]
    public string $provider = 'openai';

    #[ORM\Column(length: 64)]
    #[Groups(['ai_batch:read'])]
    public string $task = 'image_enrichment';

    #[ORM\Column(nullable: true)]
    #[Groups(['ai_batch:read'])]
    public ?string $datasetKey = null;

    #[ORM\Column(length: 32)]
    #[Groups(['ai_batch:read'])]
    #[ApiProperty(description: 'building|submitted|processing|completed|failed|expired')]
    public string $status = 'building';

    #[ORM\Column(nullable: true)]
    public ?string $inputFilePath = null;

    #[ORM\Column(nullable: true)]
    public ?string $inputFileId = null;

    #[ORM\Column(nullable: true)]
    public ?string $outputFileId = null;

    #[ORM\Column(nullable: true)]
    public ?string $errorFileId = null;

    #[ORM\Column]
    #[Groups(['ai_batch:read'])]
    public int $requestCount = 0;

    #[ORM\Column]
    #[Groups(['ai_batch:read'])]
    public int $completedCount = 0;

    #[ORM\Column]
    #[Groups(['ai_batch:read'])]
    public int $failedCount = 0;

    #[ORM\Column]
    #[Groups(['ai_batch:read'])]
    public int $appliedCount = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    #[Groups(['ai_batch:read'])]
    public ?string $estimatedCostUsd = null;

    #[ORM\Column]
    #[Groups(['ai_batch:read'])]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['ai_batch:read'])]
    public ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['ai_batch:read'])]
    public ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['ai_batch:read'])]
    public ?\DateTimeImmutable $lastPolledAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['ai_batch:read'])]
    public ?string $savedResultPath = null;

    #[ORM\Column(type: Types::JSON)]
    #[Groups(['ai_batch:read'])]
    public array $meta = [];

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['submitted', 'processing'], true);
    }

    public function isComplete(): bool { return $this->status === 'completed'; }
    public function isFailed(): bool   { return in_array($this->status, ['failed', 'expired'], true); }
    public function isBuilding(): bool { return $this->status === 'building'; }

    public function markSubmitted(string $providerBatchId, string $inputFileId): void
    {
        $this->providerBatchId = $providerBatchId;
        $this->inputFileId     = $inputFileId;
        $this->status          = 'submitted';
        $this->submittedAt     = new \DateTimeImmutable();
    }

    public function applyProviderStatus(string $status, int $completed, int $failed, ?string $outputFileId, ?string $errorFileId): void
    {
        $this->status         = match ($status) {
            'validating', 'in_progress', 'finalizing' => 'processing',
            'completed'                                => 'completed',
            'failed', 'expired', 'cancelled'           => 'failed',
            default                                    => $this->status,
        };
        $this->completedCount = $completed;
        $this->failedCount    = $failed;
        $this->outputFileId   = $outputFileId ?? $this->outputFileId;
        $this->errorFileId    = $errorFileId  ?? $this->errorFileId;
        $this->lastPolledAt   = new \DateTimeImmutable();

        if ($this->isComplete()) {
            $this->completedAt = new \DateTimeImmutable();
        }
    }
}
