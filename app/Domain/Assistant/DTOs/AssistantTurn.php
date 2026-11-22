<?php

namespace App\Domain\Assistant\DTOs;

/** One model step: either tool calls to execute, or a reply to send (with the model's confidence 0–1). */
final class AssistantTurn
{
    /** @param list<array{name: string, arguments: array}> $toolCalls */
    public function __construct(
        public readonly array $toolCalls = [],
        public readonly ?string $reply = null,
        public readonly float $confidence = 1.0,
    ) {}

    public static function reply(string $text, float $confidence = 0.9): self
    {
        return new self([], $text, $confidence);
    }

    /** @param array<string, array> $calls name => arguments */
    public static function tools(array $calls): self
    {
        return new self(array_map(fn ($name, $args) => ['name' => $name, 'arguments' => $args], array_keys($calls), array_values($calls)));
    }

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
