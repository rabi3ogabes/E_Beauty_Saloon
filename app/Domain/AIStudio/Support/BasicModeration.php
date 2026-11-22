<?php

namespace App\Domain\AIStudio\Support;

use App\Domain\AIStudio\Contracts\ModeratesContent;

/**
 * Default moderation: the file must be a real raster image of sane dimensions.
 * Replace by binding ModeratesContent to a vision safety service (nudity, violence, minors...).
 */
class BasicModeration implements ModeratesContent
{
    public function check(string $image, string $stage): ?string
    {
        $info = @getimagesizefromstring($image);
        if (! $info || ! in_array($info[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return __('ai_studio.errors.invalid_image');
        }
        [$w, $h] = $info;
        if ($stage === 'input' && ($w < 64 || $h < 64)) {
            return __('ai_studio.errors.image_too_small');
        }
        if ($w > 12000 || $h > 12000) {
            return __('ai_studio.errors.invalid_image');
        }

        return null;
    }
}
