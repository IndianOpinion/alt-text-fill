<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Adapter;

use IndianOpinion\AltTextFill\Exception\AdapterException;
use IndianOpinion\AltTextFill\Model\AdapterResponse;
use IndianOpinion\AltTextFill\Model\Context;
use IndianOpinion\AltTextFill\Model\ImageInput;
use IndianOpinion\AltTextFill\Model\Prompt;
use IndianOpinion\AltTextFill\Redactor;

/**
 * Reference adapter for any chat completions endpoint that accepts image
 * parts and speaks the widely copied OpenAI-compatible request shape.
 *
 * One POST to {base_url}/chat/completions with a system message and a user
 * message that holds the delimited context as text and the image as a data
 * URI (ruling 5: base64 only). The HTTP call goes through the
 * alt_text_fill_http_caller filter, default wp_remote_post(), so a site can
 * plug in its own caller.
 */
class OpenAiCompatibleAdapter implements VisionAdapter
{
    public function id(): string
    {
        return 'openai-compatible';
    }

    public function label(): string
    {
        return 'OpenAI-compatible chat completions endpoint';
    }

    public function capabilities(): array
    {
        return [
            'mime_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'accepts_url' => false,
            'accepts_animation' => false,
            'max_bytes' => 4 * 1024 * 1024,
        ];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'Base URL (ends before /chat/completions)', 'type' => 'url', 'secret' => false, 'default' => ''],
            ['key' => 'model', 'label' => 'Model', 'type' => 'text', 'secret' => false, 'default' => ''],
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'secret' => true, 'default' => ''],
            ['key' => 'extra_headers', 'label' => 'Extra headers, one "Name: value" per line', 'type' => 'textarea', 'secret' => false, 'default' => ''],
            ['key' => 'json_mode', 'label' => 'Ask for a JSON object response', 'type' => 'checkbox', 'secret' => false, 'default' => true],
        ];
    }

    public function validateSettings(array $settings): array
    {
        $errors = [];
        $base = (string) ($settings['base_url'] ?? '');
        if ($base === '' || !preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $base)) {
            $errors[] = 'Set a base URL that starts with https:// (or http:// for a local server).';
        }
        if (trim((string) ($settings['model'] ?? '')) === '') {
            $errors[] = 'Set a model ID.';
        }
        if (trim((string) ($settings['api_key'] ?? '')) === '' && empty($settings['keyless'])) {
            $errors[] = 'Set an API key, or define ALT_TEXT_FILL_API_KEY.';
        }
        foreach (self::headers((string) ($settings['extra_headers'] ?? '')) as $name => $value) {
            if (strcasecmp($name, 'authorization') === 0) {
                $errors[] = 'Extra headers must not set Authorization; use the API key field.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function buildRequest(ImageInput $image, Prompt $prompt, array $settings): array
    {
        $imageUrl = $image->isUrl() ? (string) $image->url : $image->dataUri();
        $body = [
            'model' => (string) ($settings['model'] ?? ''),
            'messages' => [
                ['role' => 'system', 'content' => $prompt->system],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $prompt->user],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                ]],
            ],
            'max_tokens' => (int) ($settings['max_tokens'] ?? 200),
            'temperature' => (float) ($settings['temperature'] ?? 0.2),
        ];
        $jsonMode = $settings['json_mode'] ?? true;
        if ($jsonMode === true || $jsonMode === '1' || $jsonMode === 1) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        $headers = ['Content-Type' => 'application/json'];
        $key = trim((string) ($settings['api_key'] ?? ''));
        if ($key !== '') {
            Redactor::register($key);
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        foreach (self::headers((string) ($settings['extra_headers'] ?? '')) as $name => $value) {
            if (strcasecmp($name, 'authorization') !== 0) {
                $headers[$name] = $value;
            }
        }

        return [
            'url' => rtrim((string) ($settings['base_url'] ?? ''), '/') . '/chat/completions',
            'args' => [
                'method' => 'POST',
                'timeout' => (int) ($settings['timeout'] ?? 30),
                'redirection' => 0,
                'headers' => $headers,
                'body' => (string) wp_json_encode($body),
                'data_format' => 'body',
            ],
        ];
    }

    public function describe(ImageInput $image, Context $context, Prompt $prompt, array $settings): AdapterResponse
    {
        $errors = $this->validateSettings($settings);
        if ($errors !== []) {
            throw new AdapterException('Adapter settings are incomplete: ' . implode(' ', $errors));
        }
        $request = $this->buildRequest($image, $prompt, $settings);
        $caller = apply_filters('alt_text_fill_http_caller', null, $this->id());
        $started = microtime(true);
        $response = is_callable($caller)
            ? $caller($request['url'], $request['args'])
            : wp_remote_post($request['url'], $request['args']);
        $latency = microtime(true) - $started;

        if (is_wp_error($response)) {
            throw new AdapterException('Request failed: ' . Redactor::redact($response->get_error_message()), true);
        }
        if (!is_array($response)) {
            throw new AdapterException('The HTTP caller returned an unexpected value.');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        $json = is_array($json) ? $json : [];
        if ($status < 200 || $status >= 300) {
            throw $this->errorFor($status, $json, $response);
        }
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
            $content = $text;
        }
        if (!is_string($content)) {
            throw new AdapterException('The answer has no message content.', false, false, null, $status);
        }
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];

        return new AdapterResponse(
            $content,
            is_string($json['model'] ?? null) ? $json['model'] : (string) ($settings['model'] ?? ''),
            isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            $status,
            $latency
        );
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed> $response
     */
    private function errorFor(int $status, array $json, array $response): AdapterException
    {
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = (string) ($error['code'] ?? ($error['type'] ?? ''));
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'HTTP ' . $status;
        $message = 'Provider answered ' . $status . ': ' . Redactor::redact(mb_substr($message, 0, 200));
        if (in_array($status, [401, 402, 403], true) || in_array($code, ['insufficient_quota', 'billing_hard_limit_reached', 'invalid_api_key'], true)) {
            return new AdapterException($message, false, true, null, $status);
        }
        if ($status === 429) {
            $header = wp_remote_retrieve_header($response, 'retry-after');
            $header = is_array($header) ? (string) reset($header) : (string) $header;
            $retryAfter = self::parseRetryAfter($header);

            return new AdapterException($message, false, false, $retryAfter, $status);
        }
        if ($status >= 500) {
            return new AdapterException($message, true, false, null, $status);
        }

        return new AdapterException($message, false, false, null, $status);
    }

    public static function parseRetryAfter(string $header): int
    {
        $header = trim($header);
        if ($header === '') {
            return 60;
        }
        if (ctype_digit($header)) {
            return max(1, min(86400, (int) $header));
        }
        $time = strtotime($header);

        return $time === false ? 60 : max(1, min(86400, $time - time()));
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $lines): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $lines) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z0-9-]{1,64})\s*:\s*(.{1,500})$/', $line, $m) === 1) {
                $out[$m[1]] = trim($m[2]);
            }
        }

        return $out;
    }
}
