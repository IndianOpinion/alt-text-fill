<?php

declare(strict_types=1);

namespace IndianOpinion\AltTextFill\Tests\Support;

use IndianOpinion\AltTextFill\Logger;

final class RecordingLogger extends Logger
{
    /** @var list<string> */
    public array $written = [];

    protected function write(string $line): void
    {
        $this->written[] = $line;
    }
}
