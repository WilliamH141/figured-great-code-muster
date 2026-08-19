<?php

namespace App\Services;

use RuntimeException;

class AnthropicException extends RuntimeException
{
    public function __construct(string $message, protected int $status = 500)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
