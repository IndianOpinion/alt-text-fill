<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Adapter;

use IndianOpinion\AltTextFill\Exception\AdapterException;
use IndianOpinion\AltTextFill\Model\AdapterResponse;
use IndianOpinion\AltTextFill\Model\Context;
use IndianOpinion\AltTextFill\Model\ImageInput;
use IndianOpinion\AltTextFill\Model\Prompt;

/**
 * Returns canned answers. For tests, demos and dry rehearsals: it never
 * leaves the site. Queue answers or exceptions with push(); with an empty
 * queue it returns the "answer" setting, or a neutral default.
 */
class FakeAdapter implements VisionAdapter
{
    /** @var list<string|\Throwable> */
    private array $queue = [];
    /** @var list<array{image: ImageInput, context: Context, prompt: Prompt}> */
    public array $calls = [];
    public float $latency = 0.0;
    /** @var callable|null Called before each answer, e.g. to move a test clock. */
    public $onCall = null;

    /**
     * @param array{mime_types?: list<string>, accepts_url?: bool, accepts_animation?: bool, max_bytes?: int} $capabilities
     */
    public function __construct(private array $capabilities = [], private ?int $inputTokens = 100, private ?int $outputTokens = 20)
    {
    }

    public function id(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake (canned answers, no network)';
    }

    public function capabilities(): array
    {
        return [
            'mime_types' => $this->capabilities['mime_types'] ?? ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
            'accepts_url' => $this->capabilities['accepts_url'] ?? false,
            'accepts_animation' => $this->capabilities['accepts_animation'] ?? false,
            'max_bytes' => $this->capabilities['max_bytes'] ?? 20 * 1024 * 1024,
        ];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'answer', 'label' => 'Canned JSON answer', 'type' => 'textarea', 'secret' => false, 'default' => ''],
        ];
    }

    public function validateSettings(array $settings): array
    {
        return [];
    }

    public function push(string|\Throwable ...$answers): self
    {
        foreach ($answers as $answer) {
            $this->queue[] = $answer;
        }

        return $this;
    }

    public function describe(ImageInput $image, Context $context, Prompt $prompt, array $settings): AdapterResponse
    {
        $this->calls[] = ['image' => $image, 'context' => $context, 'prompt' => $prompt];
        if ($this->onCall !== null) {
            ($this->onCall)($this);
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next === null) {
            $canned = isset($settings['answer']) && is_string($settings['answer']) ? trim($settings['answer']) : '';
            $next = $canned !== '' ? $canned : (string) json_encode([
                'alt' => 'A canned description from the fake adapter',
                'decorative' => false,
                'contains_text' => false,
                'names_person' => false,
                'matches_context' => true,
                'confidence' => 0.9,
            ]);
        }

        return new AdapterResponse($next, 'fake-model', $this->inputTokens, $this->outputTokens, 200, $this->latency);
    }

    public static function failure(string $message = 'Fake failure', bool $retryable = false, bool $account = false, ?int $retryAfter = null, int $status = 0): AdapterException
    {
        return new AdapterException($message, $retryable, $account, $retryAfter, $status);
    }
}
