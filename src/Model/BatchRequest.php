<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Model;

/**
 * A single request in a batch submission.
 *
 * customId is YOUR identifier — it is echoed verbatim in BatchResult,
 * allowing correct mapping regardless of output ordering.
 *
 * Examples:
 *   "fortepan_1"          — Fortepan photo id
 *   "dc_v405v7776"        — DC ark id
 *   "pp_fauquierhistory_UUID"
 */
final class BatchRequest
{
    public function __construct(
        public readonly string $customId,
        /** System prompt */
        public readonly string $systemPrompt,
        /** User prompt — may include image_url for vision tasks */
        public readonly string $userPrompt,
        public readonly string $model,
        /** Image URL for vision tasks (optional) */
        public readonly ?string $imageUrl    = null,
        /** Structured output class name for typed responses */
        public readonly ?string $responseFormatClass = null,
        /** max_tokens, temperature, etc. */
        public readonly array   $options     = [],
    ) {}

    /**
     * Serialize to OpenAI batch JSONL line.
     * Image is passed as image_url (not base64) to stay under 200MB file limit.
     */
    public function toOpenAiLine(): array
    {
        $userContent = $this->imageUrl !== null
            ? [
                ['type' => 'text', 'text' => $this->userPrompt],
                ['type' => 'image_url', 'image_url' => ['url' => $this->imageUrl, 'detail' => 'low']],
              ]
            : $this->userPrompt;

        $body = array_merge([
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt],
                ['role' => 'user',   'content' => $userContent],
            ],
        ], $this->options);

        if ($this->responseFormatClass !== null) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return [
            'custom_id' => $this->customId,
            'method'    => 'POST',
            'url'       => '/v1/chat/completions',
            'body'      => $body,
        ];
    }

    /**
     * Serialize to Anthropic batch request format.
     */
    public function toAnthropicLine(): array
    {
        $userContent = $this->imageUrl !== null
            ? [
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => $this->imageUrl]],
                ['type' => 'text',  'text'   => $this->userPrompt],
              ]
            : $this->userPrompt;

        return [
            'custom_id' => $this->customId,
            'params'    => array_merge([
                'model'      => $this->model,
                'max_tokens' => $this->options['max_tokens'] ?? 1024,
                'system'     => $this->systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userContent],
                ],
            ], array_diff_key($this->options, ['max_tokens' => true])),
        ];
    }
}
