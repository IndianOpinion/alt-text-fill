<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Exception;

/**
 * The kill switch, a pause, or dry-run-only mode refused a real run.
 */
class DisabledException extends AltTextFillException
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
