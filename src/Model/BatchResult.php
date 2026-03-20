<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Model;

/**
 * A single result from a completed batch.
 * customId maps back to the BatchRequest that produced it.
 */
final class BatchResult
{
    public function __construct(
        public readonly string  $customId,
        /** Parsed response content — array when JSON, string otherwise */
        public readonly array|string|null $content,
        public readonly bool    $success,
        public readonly ?string $error        = null,
        public readonly ?string $errorCode    = null,
        public readonly int     $promptTokens = 0,
        public readonly int     $outputTokens = 0,
        public readonly array   $raw          = [],
    ) {}

    public static function fromOpenAiLine(array $line): self
    {
        $response = $line['response'] ?? null;
        $error    = $line['error']    ?? null;

        if ($error !== null) {
            return new self(
                customId:  $line['custom_id'],
                content:   null,
                success:   false,
                error:     $error['message'] ?? 'Unknown error',
                errorCode: $error['code']    ?? null,
                raw:       $line,
            );
        }

        $body    = $response['body']    ?? [];
        $choice  = $body['choices'][0]  ?? [];
        $message = $choice['message']   ?? [];
        $raw     = $message['content']  ?? '';
        $usage   = $body['usage']       ?? [];

        // Attempt JSON decode for structured outputs
        $content = $raw;
        if (is_string($raw) && str_starts_with(ltrim($raw), '{')) {
            try {
                $content = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $content = $raw;
            }
        }

        return new self(
            customId:     $line['custom_id'],
            content:      $content,
            success:      true,
            promptTokens: $usage['prompt_tokens']     ?? 0,
            outputTokens: $usage['completion_tokens'] ?? 0,
            raw:          $line,
        );
    }

    public static function fromAnthropicLine(array $line): self
    {
        $result = $line['result'] ?? [];
        $type   = $result['type'] ?? 'error';

        if ($type === 'error' || $type === 'expired') {
            return new self(
                customId:  $line['custom_id'],
                content:   null,
                success:   false,
                error:     $result['error']['message'] ?? $type,
                errorCode: $result['error']['type']    ?? $type,
                raw:       $line,
            );
        }

        $message = $result['message'] ?? [];
        $content = $message['content'][0]['text'] ?? '';
        $usage   = $message['usage'] ?? [];

        if (is_string($content) && str_starts_with(ltrim($content), '{')) {
            try {
                $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {}
        }

        return new self(
            customId:     $line['custom_id'],
            content:      $content,
            success:      true,
            promptTokens: $usage['input_tokens']  ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            raw:          $line,
        );
    }
}
