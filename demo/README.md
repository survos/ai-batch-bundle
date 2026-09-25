# Native Symfony AI batch demo

The demo uses Symfony AI 0.14's OpenAI Responses batch API. It has no fork,
Survos batch-client, database, or scheduler dependency. The parent bundle supplies
application persistence/orchestration; this example isolates the native transport.

```bash
composer install
# Set OPENAI_API_KEY=sk-... in .env.local
bin/console app:ads --limit=2
bin/console app:ads --batch --limit=10
# Use the handle path printed by submission:
bin/console app:fetch-batch var/batches/batch_ID.json
bin/console app:fetch-batch var/batches/batch_ID.json --watch --timeout=3600
```

The product source is `data/products.json`. The default limit is two; `--limit=0`
processes all products. Both modes build identical message bags with `product_ID`
keys. Batch submission uses `invoke($model, $inputs, ['batch' => true])->asJob()`;
Symfony uploads the JSONL and creates the job. The saved `JobHandle` contains the
provider, endpoint, and polling information needed by a later process.

Fetch writes JSONL beside the handle, or to `--output=path.jsonl`. It preserves
per-request errors and canceled/expired partial results, and returns a nonzero exit
code for failed outcomes. Pending jobs without `--watch` return normally without
creating an output file. A watch timeout does not cancel the remote job. Old numeric
AiBatch database IDs and Chat Completions handles cannot be resumed by this demo.

```bash
composer test
OPENAI_API_KEY=sk-test APP_SECRET=test APP_ENV=test bin/console lint:container
```

Tests use MockHttpClient through the real released bridge; they make no live AI calls.
