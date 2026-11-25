<?php

namespace App\Domain\AIStudio\Providers;

use App\Domain\AIStudio\Models\AIProvider;
use InvalidArgumentException;

class ProviderFactory
{
    /** @var array<string, \Closure(AIProvider): AbstractDriver> */
    protected array $extensions = [];

    /** Register a custom driver: app(ProviderFactory::class)->extend('replicate', fn ($row) => new ReplicateDriver($row)). */
    public function extend(string $driver, \Closure $factory): void
    {
        $this->extensions[$driver] = $factory;
    }

    public function make(AIProvider $provider): AbstractDriver
    {
        if (isset($this->extensions[$provider->driver])) {
            return ($this->extensions[$provider->driver])($provider);
        }

        return match ($provider->driver) {
            'gemini' => new GeminiDriver($provider),
            'openai' => new OpenAIDriver($provider),
            'higgsfield' => new HiggsfieldDriver($provider),
            'anthropic' => new AnthropicDriver($provider),
            'openrouter' => new OpenRouterDriver($provider),
            'http' => new GenericHttpDriver($provider),
            default => throw new InvalidArgumentException("Unknown AI driver [{$provider->driver}]"),
        };
    }
}
