<?php

namespace App\Domain\Assistant\Drivers;

use App\Domain\Assistant\Contracts\AssistantModel;
use App\Domain\Assistant\DTOs\AssistantTurn;

/**
 * Scripted model for tests and demos: returns the queued turns in order (null = "no model available").
 * Records every call so tests can assert what the model was shown.
 *   app()->instance(AssistantModel::class, new FakeAssistantModel([AssistantTurn::tools([...]), AssistantTurn::reply('...')]));
 */
class FakeAssistantModel implements AssistantModel
{
    /** @var list<array{system: string, messages: array, tools: array}> */
    public array $calls = [];

    /** @param list<AssistantTurn|null> $turns */
    public function __construct(public array $turns = []) {}

    public function next(string $system, array $messages, array $tools): ?AssistantTurn
    {
        $this->calls[] = compact('system', 'messages', 'tools');

        return array_shift($this->turns);
    }
}
