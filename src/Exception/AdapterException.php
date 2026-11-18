<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Exception;

/**
 * A vision adapter call failed. The flags say what the runner does next.
 */
class AdapterException extends AltTextFillException
{
    public function __construct(
        string $message,
        private bool $retryable = false,
        private bool $accountError = false,
        private ?int $retryAfter = null,
        private int $httpStatus = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** True for a timeout or a 5xx answer: one retry is allowed in the same run. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /** True for 401, 402, 403 or a documented quota error: the plugin pauses. */
    public function isAccountError(): bool
    {
        return $this->accountError;
    }

    /** Seconds to wait after a 429, or null when this was not a rate limit. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isRateLimit(): bool
    {
        return $this->retryAfter !== null;
    }
}
