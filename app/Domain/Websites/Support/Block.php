<?php

namespace App\Domain\Websites\Support;

/** A platform CMS section: ['type' => 'hero', 'data' => ['en' => [...], 'ar' => [...], ...shared]]. */
final class Block
{
    public readonly string $type;

    public readonly array $data;

    public function __construct(array $raw)
    {
        $this->type = (string) ($raw['type'] ?? 'text');
        $this->data = (array) ($raw['data'] ?? []);
    }

    public function t(string $key, ?string $default = null): ?string
    {
        $value = data_get($this->data, app()->getLocale().'.'.$key);
        if (blank($value)) {
            $value = data_get($this->data, 'en.'.$key);
        }

        return filled($value) ? (string) $value : $default;
    }

    public function list(string $key): array
    {
        $items = data_get($this->data, app()->getLocale().'.'.$key);
        if (blank($items)) {
            $items = data_get($this->data, 'en.'.$key, []);
        }

        return array_values(array_filter((array) $items, fn ($i) => is_array($i) ? array_filter($i) : filled($i)));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
