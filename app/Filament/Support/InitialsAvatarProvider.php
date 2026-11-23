<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/** Local SVG initials avatar (no third-party requests, works offline, Arabic-safe). */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));
        $initials = collect(preg_split('/\s+/u', $name))->filter()->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '•';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="32" fill="#F3EADB"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Playfair Display, Georgia, serif" font-size="24" fill="#A88B4F">'
            .e($initials).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
