# Session Summary — tacman/ai-batch-bundle

## What This Is
Async batch AI processing for Symfony. Proposed for inclusion in `symfony/ai`
as `BatchCapablePlatformInterface`.

**Key insight:** The OpenAI Batch API costs 50% less than sync calls and has
higher rate limits. Running 5 AI tasks per image costs 5× the image-parsing fee;
`enrich_from_thumbnail` does it in one call.

## Files Created

### Interface (proposed for symfony/ai core)
- `src/Contract/BatchCapablePlatformInterface.php`
  - `supportsBatch()`, `submitBatch()`, `checkBatch()`, `fetchResults()`, `cancelBatch()`

### Models
- `src/Model/BatchJob.php` — lifecycle tracking, `fromOpenAiArray()`, `fromAnthropicArray()`
- `src/Model/BatchRequest.php` — `toOpenAiLine()`, `toAnthropicLine()`
- `src/Model/BatchResult.php` — `fromOpenAiLine()`, `fromAnthropicLine()`

### Provider implementations
- `src/Service/OpenAiBatchClient.php` — full OpenAI Batch API
- `src/Service/AnthropicBatchClient.php` — Anthropic Message Batches API

### Infrastructure
- `src/Entity/AiBatch.php` — Doctrine entity, lifecycle tracking
- `src/Service/AiBatchBuilder.php` — build + submit from any iterable
- `src/Message/PollBatchesMessage.php` + `ApplyBatchResultsMessage.php`
- `src/Scheduler/PollBatchesTask.php` — Symfony Scheduler, polls every 2 min
- `src/TacmanAiBatchBundle.php` — auto-registers entity + Scheduler task

### Demo app (`demo/`)
- `app:load --fetch --submit` — fetch dummyjson products, submit batch
- `ai:batch:create/status/download/wait/list` — bundle commands
- No Doctrine needed for demo — file-based

## Commands (provided by bundle)
```
ai:batch:create <file>     Upload JSONL, create batch
ai:batch:status <id>       Check status
ai:batch:download <id>     Download results (fails if not complete)
ai:batch:wait <id>         Poll until complete, optionally download
ai:batch:list              List recent batches
```

## Cost comparison
- 5 separate tasks: ~$0.0020/image
- `enrich_from_thumbnail` (single call): ~$0.0004/image
- Full batch API discount: 50% off → ~$0.0002/image

## TODO
- Push to github.com/tacman/ai-batch-bundle
- Submit `BatchCapablePlatformInterface` PR to symfony/ai
- Wire into `FortepanAiEnrichCommand` for large-scale processing
- PDF multi-page support (custom_id: `{mediaKey}:{page}`)
- Google Vertex AI batch prediction support
