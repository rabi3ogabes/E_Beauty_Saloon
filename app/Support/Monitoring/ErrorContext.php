<?php

namespace App\Support\Monitoring;

use Illuminate\Support\Str;
use Throwable;

/** What an alert says: the error, where it happened in the code, and which request / salon / user hit it. */
final class ErrorContext
{
    public function __construct(
        public readonly string $class,
        public readonly string $message,
        public readonly string $location,
        public readonly array $frames,
        public readonly array $request,
        public readonly int $repeats,
        public readonly string $environment,
        public readonly string $time,
    ) {}

    public static function from(Throwable $e, int $repeats = 0): self
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        $short = fn (?string $file) => $file ? Str::after($file, $base) : '?';

        // App frames first (they point at our code), then a few framework ones for context.
        $frames = collect($e->getTrace())->map(fn ($f) => [
            'at' => $short($f['file'] ?? null).':'.($f['line'] ?? '?'),
            'call' => trim(($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? ''), ':->'),
            'app' => isset($f['file']) && ! str_contains($f['file'], DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR),
        ]);
        $frames = $frames->where('app', true)->take(10)->merge($frames->where('app', false)->take(4))->values()->all();

        $r = app()->bound('request') ? request() : null;
        if ($r && $r->route()) { // an HTTP request reached the router
            $user = $r->user();
            $request = array_filter([
                'url' => Str::limit($r->method().' '.$r->fullUrl(), 300),
                'route' => $r->route()?->getName(),
                'salon' => tenant()?->name ? tenant()->name.' ('.tenant()->slug.')' : null,
                'user' => $user ? '#'.$user->getAuthIdentifier().' '.($user->email ?? '') : 'guest',
                'agent' => Str::limit((string) $r->userAgent(), 160),
            ]);
        } else {
            $request = ['command' => Str::limit(implode(' ', array_slice($_SERVER['argv'] ?? ['artisan'], 0, 6)), 200)];
        }

        return new self(
            class: get_class($e),
            message: Str::limit($e->getMessage() ?: '(no message)', 600),
            location: $short($e->getFile()).':'.$e->getLine(),
            frames: $frames,
            request: $request,
            repeats: $repeats,
            environment: app()->environment(),
            time: now()->setTimezone(config('app.display_timezone', 'Asia/Qatar'))->format('d M Y, H:i:s T'),
        );
    }
}
