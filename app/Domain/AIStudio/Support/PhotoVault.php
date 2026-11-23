<?php

namespace App\Domain\AIStudio\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Client photos: encrypted at rest (Crypt / APP_KEY) on the private "local" disk, never publicly served.
 * Photos are never used for training — they are only sent to the routed provider for the requested edit.
 */
class PhotoVault
{
    public function put(int $tenantId, string $binary, string $kind = 'photo'): string
    {
        $path = 'ai-studio/'.$tenantId.'/'.now()->format('Y/m').'/'.$kind.'-'.Str::ulid().'.enc';
        Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($binary)));

        return $path;
    }

    public function get(?string $path): ?string
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return base64_decode(Crypt::decryptString(Storage::disk('local')->get($path)));
    }

    public function delete(?string ...$paths): void
    {
        $paths = array_values(array_filter($paths));
        if ($paths) {
            Storage::disk('local')->delete($paths);
        }
    }

    public static function mime(string $binary): string
    {
        $info = @getimagesizefromstring($binary);

        return $info['mime'] ?? 'image/jpeg';
    }
}
