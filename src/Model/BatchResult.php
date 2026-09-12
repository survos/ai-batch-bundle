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
        /**
         * The provider's full response body for this line (OpenAI/Mistral response.body, Anthropic
         * result.message) -- what a synchronous call would have returned, so a task can parse a
         * batch result with the same code as its sync response. $content is the chat text only.
         */
        public readonly ?array  $body         = null,
    ) {}

    /** Re-parse a raw provider line (e.g. one archived from $raw) by the provider's name. */
    public static function fromProviderLine(string $provider, array $line): self
    {
        return match ($provider) {
            'openai'    => self::fromOpenAiLine($line),
            'anthropic' => self::fromAnthropicLine($line),
            'mistral'   => self::fromMistralLine($line),
            default     => throw new \InvalidArgumentException(sprintf('Unknown batch provider "%s".', $provider)),
        };
    }

    public static function fromOpenAiLine(array $line): self
    {
        $response = $line['response'] ?? null;
        $error    = $line['error'] ?? $response['body']['error'] ?? null;
        if ($error === null && (int) ($response['status_code'] ?? 200) >= 400) {
            $error = ['message' => 'HTTP ' . $response['status_code'], 'code' => (string) $response['status_code']];
        }

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
            body:         is_array($body) ? $body : null,
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
            body:         $message ?: null,
        );
    }

    /**
     * One line of a Mistral batch output or error file:
     * {id, custom_id, response: {status_code, body}, error}. For chat the content is the message
     * text (JSON-decoded when it is JSON); for other endpoints (/v1/ocr) it is the body itself.
     */
    public static function fromMistralLine(array $line): self
    {
        $response = $line['response'] ?? null;
        $status   = (int) ($response['status_code'] ?? 0);
        // Mistral sends `body` as a JSON STRING, not an object -- on error lines for certain
        // (measured 2026-09-12). Decode either shape, or every result is silently dropped.
        $body     = $response['body'] ?? null;
        if (is_string($body)) {
            try {
                $body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $body = null;
            }
        }
        $body     = is_array($body) ? $body : null;
        $error    = $line['error'] ?? null;

        if ($error !== null || $body === null || ($status !== 0 && $status >= 400)) {
            $message = is_array($error) ? ($error['message'] ?? json_encode($error)) : (is_string($error) ? $error : null);
            $message ??= is_array($body)
                ? (is_string($body['message'] ?? null) ? $body['message'] : json_encode($body['detail'] ?? $body))
                : 'no response';

            return new self(
                customId:  (string) $line['custom_id'],
                content:   null,
                success:   false,
                error:     (string) $message,
                errorCode: (string) ($body['code'] ?? ($status > 0 ? $status : (is_array($error) ? ($error['code'] ?? '') : ''))) ?: null,
                raw:       $line,
                body:      $body,
            );
        }

        $content = $body;
        if (isset($body['choices'][0]['message'])) {
            $content = $body['choices'][0]['message']['content'] ?? '';
            if (is_string($content) && str_starts_with(ltrim($content), '{')) {
                try {
                    $content = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {}
            }
        }
        $usage = $body['usage'] ?? $body['usage_info'] ?? [];

        return new self(
            customId:     (string) $line['custom_id'],
            content:      $content,
            success:      true,
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            raw:          $line,
            body:         $body,
        );
    }
}
