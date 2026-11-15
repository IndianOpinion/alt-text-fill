<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Adapter;

use IndianOpinion\AltTextFill\Settings;

/**
 * The bundled adapters plus any registered through alt_text_fill_adapters.
 */
class AdapterRegistry
{
    /** @var array<string, VisionAdapter>|null */
    private ?array $adapters = null;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * @return array<string, VisionAdapter>
     */
    public function all(): array
    {
        if ($this->adapters !== null) {
            return $this->adapters;
        }
        $bundled = [
            'openai-compatible' => new OpenAiCompatibleAdapter(),
            'fake' => new FakeAdapter(),
        ];
        $filtered = apply_filters('alt_text_fill_adapters', $bundled);
        $out = [];
        foreach (is_array($filtered) ? $filtered : $bundled as $adapter) {
            if ($adapter instanceof VisionAdapter && preg_match('/^[a-z0-9-]{1,40}$/', $adapter->id()) === 1) {
                $out[$adapter->id()] = $adapter;
            }
        }
        $this->adapters = $out;

        return $out;
    }

    public function get(string $id): ?VisionAdapter
    {
        return $this->all()[$id] ?? null;
    }

    public function active(): ?VisionAdapter
    {
        $id = $this->settings->string('adapter');

        return $id === '' ? null : $this->get($id);
    }

    public function reset(): void
    {
        $this->adapters = null;
    }
}
