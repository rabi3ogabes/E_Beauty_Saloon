<?php

namespace App\Domain\Assistant\Drivers;

use App\Domain\AIStudio\Providers\AbstractDriver;
use App\Domain\Assistant\Contracts\AssistantModel;
use App\Domain\Assistant\DTOs\AssistantTurn;
use App\Domain\Insights\Support\SmartText;

/**
 * Default AssistantModel: a JSON tool-calling protocol that works with ANY text provider behind the AI Studio
 * router (Claude, Gemini, OpenAI, generic HTTP), with fallback between providers and credits per step.
 * The model must answer {"tool_calls":[{"name":"...","arguments":{...}}]} or {"reply":"...","confidence":0.9}.
 * Anything unparseable becomes a low-confidence reply (→ human handoff).
 */
class RouterAssistantModel implements AssistantModel
{
    public function __construct(protected SmartText $ai) {}

    public function next(string $system, array $messages, array $tools): ?AssistantTurn
    {
        $prompt = $system
            ."\n\nTOOLS (call them to look things up or act; never invent prices, times or bookings):\n".json_encode($tools, JSON_UNESCAPED_UNICODE)
            ."\n\nRespond with ONLY one JSON object, either {\"tool_calls\":[{\"name\":\"tool\",\"arguments\":{}}]} "
            .'or {"reply":"text for the client","confidence":0.0-1.0}.'
            ."\n\nCONVERSATION:\n".collect($messages)->map(fn ($m) => strtoupper($m['role']).': '.$m['content'])->implode("\n");

        $result = $this->ai->complete('assistant_reply', $prompt);
        if (! $result) {
            return null;
        }

        try {
            $json = AbstractDriver::extractJson($result['text']);
        } catch (\Throwable) {
            return AssistantTurn::reply(trim($result['text']), 0.3);
        }

        $calls = collect((array) ($json['tool_calls'] ?? []))
            ->filter(fn ($c) => is_array($c) && is_string($c['name'] ?? null))
            ->map(fn ($c) => ['name' => $c['name'], 'arguments' => is_array($c['arguments'] ?? null) ? $c['arguments'] : []])
            ->values()->all();

        return new AssistantTurn($calls, isset($json['reply']) && is_string($json['reply']) ? trim($json['reply']) : null,
            is_numeric($json['confidence'] ?? null) ? max(0.0, min(1.0, (float) $json['confidence'])) : 0.8);
    }
}
