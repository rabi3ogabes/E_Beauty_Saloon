<?php

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\DTOs\AssistantTurn;

/**
 * Provider-agnostic structured tool calling. Implementations decide how to talk to a model (JSON protocol over the
 * AI Studio router by default); the receptionist only sees tool calls, replies and a confidence.
 */
interface AssistantModel
{
    /**
     * @param  string  $system  instructions (salon facts, language, rules)
     * @param  list<array{role: string, content: string}>  $messages  role: client|assistant|tool
     * @param  list<array{name: string, description: string, parameters: array}>  $tools
     * @return AssistantTurn|null null when no model is available (the caller hands off to a human)
     */
    public function next(string $system, array $messages, array $tools): ?AssistantTurn;
}
