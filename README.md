# survos/ai-batch-bundle

Persistent batch orchestration for Symfony AI 0.14: durable job handles, application
batch records, progress, archives, replay, and scheduler messages. Symfony AI owns
native OpenAI submission, polling, and result conversion.

## Package identity

Install **`survos/ai-batch-bundle`** from the Survos monorepo. `tacman/ai-batch-bundle`
is the historical package name, not a second bundle to install. The PHP namespace
`Tacman\AiBatch` and `TacmanAiBatchBundle` registration are deliberately retained:
existing services, Doctrine entity metadata, application handlers, and queued messages
use those names. Renaming the Composer package does not require renaming those classes.

```bash
composer require survos/ai-batch-bundle
```

```php
Tacman\AiBatch\TacmanAiBatchBundle::class => ['all' => true],
```

The kit base registers commands and Doctrine mappings. Existing mapping name/alias
`TacmanAiBatch` is preserved. Store `OPENAI_API_KEY` in the host application's environment.
Doctrine persistence is owned by the host application; use its migration workflow.

## Native jobs with persistence

Inject `Tacman\AiBatch\Service\NativeOpenAiBatchService` for new OpenAI batches.
Build the same message bags used for synchronous calls, keyed by stable subject IDs.

```php
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Tacman\AiBatch\Entity\AiBatch;

$batch = new AiBatch();
$batch->datasetKey = 'my-collection';
$batch->task = 'description';
$entityManager->persist($batch);
$entityManager->flush();

$handle = $nativeBatch->submit($batch, 'gpt-4o-mini', [
    'object_123' => new MessageBag(Message::ofUser('Describe this object.')),
]);
$entityManager->flush();
```

The serializable handle is stored under `meta.symfony_ai_job` in the existing JSON
column. No database schema migration is required. The entity also keeps its provider
ID, request count, submission time, and application metadata. A later worker loads
that entity and resumes the provider job:

```php
$status = $nativeBatch->refresh($batch);
$entityManager->flush();
if ($status->isTerminal()) {
    $nativeBatch->archive($batch, $archivePath);
    $entityManager->flush();
}
```

Archive retrieval can fail if a terminal job has no output/error file; the exception
leaves any previous archive intact. Canceled/expired jobs can have partial output.
Each archive row preserves the subject ID, success/error/canceled/expired outcome,
content, and error. Files are atomically replaced only after a complete download.
Replay is offline:

```php
foreach (NativeOpenAiBatchService::replay($archivePath) as $row) {
    // Apply successful rows idempotently using $row['custom_id']; retain failures for review/retry.
}
```

Archives are versioned normalized JSONL (`symfony-ai-batch-v1`), not raw provider
responses. `results()` also exposes native `BatchItem` objects directly. The caller
owns persistence transactions, deduplication, and exactly-once application to subjects.
Submitting an already-associated entity is rejected. An HTTP submit and a database
flush are not atomic: if a flush fails after acceptance, retain/reconcile the returned
handle before retrying. This service does not promise remote exactly-once submission.

## Existing jobs and other providers

`OpenAiBatchClient`, `AiBatchBuilder`, `BatchRequest`, `BatchJob`, and the legacy
`BatchCapablePlatformInterface` remain compatibility APIs. Existing applications use
raw Chat Completions JSONL, uploaded file IDs, provider response bodies, and saved
archives. Symfony's new OpenAI batches use `/v1/responses`; silently sending old jobs
through that converter would break them. Native services therefore require a stored
native handle and explicitly reject legacy rows. Legacy `batch:replay` stays separate
from `NativeOpenAiBatchService::replay()`.

Anthropic and Mistral adapters remain available through `BatchClients`. Native OpenAI
support does not replace those providers' batch APIs.

The scheduler dispatches `PollBatchesMessage` every two minutes. Host handlers choose
the native service when `$batch->getJobHandle()` is non-null and their existing adapter
otherwise. They remain responsible for applying results and recording `appliedCount`.
Existing handlers are not switched automatically.

## Demo

See [demo/README.md](demo/README.md). It runs against released Symfony AI 0.14,
using files to demonstrate handle persistence across processes, without a fork,
Doctrine database, or custom provider protocol. Its local numeric database IDs from
the old demo are not inputs to the new fetch command.
