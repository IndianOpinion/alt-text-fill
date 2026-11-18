<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Exception;

/**
 * The alt value changed since the suggestion or entry was made.
 */
class ConflictException extends AltTextFillException
{
    public function __construct(string $message, private ?string $expected = null, private ?string $current = null)
    {
        parent::__construct($message);
    }

    /** The value the plugin expected to find; null means absent. */
    public function expected(): ?string
    {
        return $this->expected;
    }

    /** The value found; null means absent. */
    public function current(): ?string
    {
        return $this->current;
    }
}
