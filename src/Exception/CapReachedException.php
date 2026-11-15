<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Exception;

/**
 * A cap (daily calls, money) refused the work before it started.
 */
class CapReachedException extends AltTextFillException
{
    public function __construct(private string $stopReason, string $message)
    {
        parent::__construct($message);
    }

    public function stopReason(): string
    {
        return $this->stopReason;
    }
}
