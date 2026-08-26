<?php

namespace App\DTOs;

use App\Enums\ConversationState;
use Stringable;

class ConversationResult implements Stringable
{
    public function __construct(
        public readonly string $response,
        public readonly ConversationState $state,
        public readonly bool $duplicate = false,
    ) {}

    public function __toString(): string
    {
        return $this->response;
    }
}
