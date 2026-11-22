<?php

use App\Domain\AIStudio\Actions\CreditLedger;
use App\Domain\AIStudio\Actions\RecordConsent;
use App\Domain\AIStudio\Models\AIProvider;
use App\Domain\AIStudio\Models\AITaskPrice;
use App\Domain\AIStudio\Models\Style;
use App\Domain\Catalog\Models\Service;
use App\Domain\CRM\Models\Client;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;

if (! function_exists('aiPng')) {
    /** A real PNG (GD) so moderation accepts it. */
    function aiPng(int $w = 240, int $h = 320, string $hex = 'C8A96A'): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    function aiUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('selfie.png', aiPng());
    }

    function aiProviders(): array
    {
        AITaskPrice::seedDefaults();
        $gemini = AIProvider::create(['driver' => 'gemini', 'name' => 'Gemini', 'api_key' => 'g-key', 'priority' => 1, 'unit_costs' => ['image' => 4, 'text' => 1], 'timeout_seconds' => 5]);
        $openai = AIProvider::create(['driver' => 'openai', 'name' => 'OpenAI', 'api_key' => 'o-key', 'priority' => 2, 'unit_costs' => ['image' => 6, 'text' => 2], 'timeout_seconds' => 5]);
        $claude = AIProvider::create(['driver' => 'anthropic', 'name' => 'Claude', 'api_key' => 'a-key', 'priority' => 1, 'unit_costs' => ['text' => 2], 'timeout_seconds' => 5]);

        return compact('gemini', 'openai', 'claude');
    }

    /** Gemini image response with an inline PNG. */
    function geminiImageResponse(): array
    {
        return ['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(aiPng(200, 260, '5B1A26'))]]]]]]];
    }

    function openaiImageResponse(): array
    {
        return ['data' => [['b64_json' => base64_encode(aiPng(200, 260, 'E9765B'))]]];
    }

    function aiStyle(Tenant $tenant, array $attrs = []): Style
    {
        return asTenant($tenant, function () use ($attrs) {
            $service = Service::create(['name' => ['en' => 'Balayage', 'ar' => 'بالياج'], 'slug' => 'balayage', 'duration_minutes' => 180, 'price' => 120000]);

            return Style::create(array_merge([
                'category' => 'hair', 'option' => 'balayage', 'name' => ['en' => 'Honey balayage', 'ar' => 'بالياج عسلي'],
                'prompt' => 'Soft honey balayage, sun-kissed ends', 'task' => 'hair_tryon', 'service_id' => $service->id,
            ], $attrs));
        });
    }

    function aiClient(Tenant $tenant): Client
    {
        return asTenant($tenant, fn () => Client::create(['name' => 'Aisha Test', 'phone' => '+97455550101']));
    }

    function aiCredits(Tenant $tenant, int $credits = 100): void
    {
        asTenant($tenant, fn () => app(CreditLedger::class)->credit($tenant, $credits, 'adjustment', 'test-topup-'.$tenant->id.'-'.$credits));
    }

    function aiConsent(Tenant $tenant, Client $client, bool $adult = true): void
    {
        asTenant($tenant, fn () => app(RecordConsent::class)->handle($client, null, $adult));
    }
}
