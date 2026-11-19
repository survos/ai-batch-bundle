# Mistral batches and Symfony AI

Local review, 2026-11-18. No production migration or Mematlas workflow changes.

| Responsibility | Existing API | Native Symfony API / owner |
| --- | --- | --- |
| Submission | `MistralBatchClient::submitBatch(BatchRequest[])` | `Platform::invoke(..., ['batch'=>true])->asJob()` |
| Durable reference | `BatchJob`, provider ID | Serializable `JobHandle`; bundle stores it in existing `AiBatch.meta` |
| Poll | `checkBatch()` | `JobClientInterface::getStatus()`; scheduling stays in bundle/application |
| Progress / cancel | Provider methods | Mistral `JobClient::getProgress()` / `cancel()` outside minimal interface |
| Results | Provider bodies / `BatchResult` | `BatchItem` + ordinary chat/OCR result objects |
| Archive and replay | Raw provider JSONL | Bundle retains raw evidence and normalized native archive |
| Apply / retry | Mediary submitter/applier | Remains application orchestration, idempotent by source ID |

Symfony AI #2577 already implements Mistral chat batches. Its head conflicted with
current main in Factory and Llm ModelClient. The prepared rebase keeps current OCR
base URL and text-to-speech wiring, and normalizes max_output_tokens before choosing
the synchronous or batch path. A separate follow-up adds OCR batching and string
response-body decoding. Do not introduce the old BatchCapablePlatformInterface
upstream or silently reinterpret historical raw archives as native results.

## Eight-page live pilot

Issue: Rappahannock News, June 1, 1950. Model: mistral-ocr-4-1.
Job: 80215894-df8e-44f9-b42a-105e56f9d55f.
Submitted through the existing bundle MistralBatchClient using original page-image
URLs, not monochrome WebP crops or PDFs. Eight successes, zero failures, 37 seconds
between provider creation and completion timestamps. Reported usage: eight pages.
Estimated charge: $0.016 at $2 per 1,000 batch pages; not an invoice reconciliation.

Local raw results, request manifest and source fingerprint:
`harvest/work/rappnews-1950-06-01-mistral-batch/`.
The native job reader and provider-neutral bundle archive were then run against the
same job without resubmission. All eight Markdown values matched raw results exactly.
Original OCR, source blocks and dcrop files were not rewritten.

## Reusable document result storage

Use the rendition schema as the foundation for text/geometry, and dcrop as the
portable image-bearing projection. Current drend layout 4 is per-paper, issue-keyed;
its block coordinates are INTEGER and geometry patches VARCHAR. It therefore needs
an explicit versioned extension, not unannounced insertion of fractional Mistral
boxes. Existing dcrop typed-word storage already preserves DOUBLE coordinates and
VARIANT attributes, with substantial measured savings over duplicated JSON.

A reusable importer should store run/source/model identity and source dimensions,
raw response VARIANT, and typed text/block/box tables with fractional coordinates.
ALTO and Mistral are distinct observations, linked to the same page; inferred article
membership is a separate projection. Neither format currently offers a complete
lossless Mistral importer. Keep raw responses until that round-trip contract exists.
The claims-to-DuckDB migration is separate from provider batching.
