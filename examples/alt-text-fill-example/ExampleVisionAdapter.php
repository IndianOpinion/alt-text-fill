<?php

/**
 * An example adapter for an endpoint with its own request shape. Loaded by
 * custom-adapter.php once Alt Text Fill is active. The endpoint is a
 * placeholder: point it at a real, documented API under your own key.
 */

declare(strict_types=1);

namespace Example\AltTextFill;

use IndianOpinion\AltTextFill\Adapter\VisionAdapter;
use IndianOpinion\AltTextFill\Exception\AdapterException;
use IndianOpinion\AltTextFill\Model\AdapterResponse;
use IndianOpinion\AltTextFill\Model\Context;
use IndianOpinion\AltTextFill\Model\ImageInput;
use IndianOpinion\AltTextFill\Model\Prompt;

final class ExampleVisionAdapter implements VisionAdapter
{
    public function id(): string
    {
        return 'example-vision';
    }

    public function label(): string
    {
        return 'Example vision endpoint';
    }

    public function capabilities(): array
    {
        return ['mime_types' => ['image/jpeg', 'image/png'], 'accepts_url' => false, 'accepts_animation' => false, 'max_bytes' => 4 * 1024 * 1024];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'endpoint', 'label' => 'Endpoint URL', 'type' => 'url', 'secret' => false, 'default' => ''],
            ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'secret' => true, 'default' => ''],
        ];
    }

    public function validateSettings(array $settings): array
    {
        $errors = [];
        if (!str_starts_with((string) ($settings['endpoint'] ?? ''), 'https://')) {
            $errors[] = 'Set an https endpoint.';
        }
        if ((string) ($settings['api_key'] ?? '') === '') {
            $errors[] = 'Set an API key.';
        }

        return $errors;
    }

    public function describe(ImageInput $image, Context $context, Prompt $prompt, array $settings): AdapterResponse
    {
        $started = microtime(true);
        $response = wp_remote_post((string) $settings['endpoint'], [
            'timeout' => (int) ($settings['timeout'] ?? 30),
            'headers' => ['Content-Type' => 'application/json', 'X-Api-Key' => (string) $settings['api_key']],
            'body' => (string) wp_json_encode([
                'instructions' => $prompt->system,
                'request' => $prompt->user,
                'image_base64' => base64_encode((string) $image->bytes),
                'image_type' => $image->mimeType,
            ]),
        ]);
        if (is_wp_error($response)) {
            throw new AdapterException('Request failed: ' . $response->get_error_message(), true);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 401 || $status === 403) {
            throw new AdapterException('Key refused.', false, true, null, $status);
        }
        if ($status === 429) {
            throw new AdapterException('Rate limited.', false, false, 60, $status);
        }
        if ($status !== 200) {
            throw new AdapterException('HTTP ' . $status, $status >= 500, false, null, $status);
        }
        $json = json_decode((string) wp_remote_retrieve_body($response), true);

        return new AdapterResponse((string) ($json['text'] ?? ''), 'example-model', null, null, $status, microtime(true) - $started);
    }
}
