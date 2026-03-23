# survos/ai-batch-bundle

Async batch AI processing for Symfony. Implements the **OpenAI Batch API** and
**Anthropic Message Batches API** with a Symfony Scheduler poller.

Include Entity for storage

**Proposed for inclusion in [symfony/ai](https://github.com/symfony/ai)** as
`BatchCapablePlatformInterface` — a 5-method extension of `PlatformInterface`.

Moved from tacman to add data-bundle and other survos-specific tooling

---

## Why batch?

| | Synchronous | Batch API |
|---|---|---|
| Cost | $0.0008 / image (gpt-4o-mini) | **$0.0004 / image (50% off)** |
| Rate limits | Standard pool | **Separate, much higher pool** |
| Timeout risk | Yes (large sets) | **None — 24h window** |
| Results | Immediate | ~10 min (up to 24h) |
| Best for | Interactive, ≤100 items | **Enrichment pipelines, ≥1000 items** |


---

## Quick demo

The `demo/` directory shows the full pattern with a fun example:
generate programmer-targeted advertising copy for products from [dummyjson.com](https://dummyjson.com),
using product images (vision) + descriptions.

```bash
cd demo
composer install
# Add your OPENAI_API_KEY to .env.local

# Synchronous — 2 products, results immediately
bin/console app:ads --limit=2

# Batch — all 194 products, 50% cheaper
bin/console app:ads --batch

#  ✅ 194 products submitted to OpenAI Batch API (50% cost discount applies!)
#  ┌──────────────────┬────────────────────────────┐
#  │ Local batch ID   │ 1                          │
#  │ Provider batch   │ batch_6789abc...            │
#  │ Status           │ submitted                  │
#  │ Requests         │ 194                        │
#  │ Est. cost        │ $0.0776                    │
#  │ vs sync cost     │ $0.1552 (you save $0.0776) │
#  └──────────────────┴────────────────────────────┘
#
#  Results will be ready in ~10 minutes.
#    bin/console app:fetch-batch 1

# Check status and display results
bin/console app:fetch-batch 1

#  > ⏳ Still processing (47 / 194)

bin/console app:fetch-batch 1

#  > ✅ completed
#
#  Product #1 — Essence Mascara Lash Princess ($9.99)
#    Like git blame for your lashes — it shows exactly who's responsible
#    for those dramatic, volumizing commits. Cruelty-free, just like your
#    code reviews should be.
#
#  Product #2 — Fingertip Skateboard ($29.99)
#    Finally, something you can debug with your fingers. Ships in 3-5 days,
#    which is faster than your CI pipeline.

# Watch mode — polls every 30s until done
bin/console app:fetch-batch 1 --watch
```

---

## Installation

```bash
composer require tacman/ai-batch-bundle
```

Add to `config/bundles.php`:
```php
Tacman\AiBatch\TacmanAiBatchBundle::class => ['all' => true],
```

Add to `.env`:
```
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...  # optional
```

Run the schema update:
```bash
bin/console doctrine:schema:update --force
```

---

## Usage

### Build and submit a batch

```php
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Service\AiBatchBuilder;

$batch = $batchBuilder->build(
    datasetKey:     'my-collection',
    task:           'image_enrichment',
    records:        $normalizedRecords,         // iterable
    requestFactory: fn(array $row) => new BatchRequest(
        customId:     $row['id'],
        systemPrompt: 'You are a museum cataloguer...',
        userPrompt:   'Describe this image and extract keywords.',
        model:        'gpt-4o-mini',
        imageUrl:     $row['thumbnail_url'],    // image_url, not base64
    ),
);

$batch = $batchBuilder->submit($batch);
$entityManager->persist($batch);
$entityManager->flush();

echo "Batch #{$batch->id} submitted: {$batch->providerBatchId}";
```

### Check and apply results

```php
$job = $batchClient->checkBatch($batch->providerBatchId);

if ($job->isComplete()) {
    foreach ($batchClient->fetchResults($job) as $result) {
        // $result->customId maps back to your record id
        // $result->content is the parsed JSON response
        $enrichment = MediaEnrichment::fromNormalized($records[$result->customId]);
        $enrichment->applyAiEnrichment($result->content);
        // push to zm, update DB, etc.
    }
}
```

### Automatic polling with Symfony Scheduler

The bundle registers a `PollBatchesTask` that fires every 2 minutes.
It dispatches `PollBatchesMessage` which your handler processes:

```php
// In your app — implement a handler that calls checkBatch() on all
// AiBatch entities with status='processing'
```

---


## License

MIT — contributions welcome.
