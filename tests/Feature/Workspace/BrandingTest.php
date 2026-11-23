<?php

require_once __DIR__.'/WorkspaceHelpers.php';

use App\Domain\Workspace\Actions\ExtractBrandPalette;
use App\Domain\Workspace\Actions\GenerateBrandKit;
use App\Domain\Workspace\Actions\UpdateBranding;
use App\Domain\Workspace\Branding\Color;
use App\Filament\Dashboard\Pages\Branding;
use App\Support\Exceptions\DomainException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** A white canvas with a large burgundy mark and a small gold accent, like a real logo. */
function ws_fake_logo(): string
{
    $img = imagecreatetruecolor(200, 100);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imagefilledrectangle($img, 10, 10, 130, 90, imagecolorallocate($img, 122, 28, 52));   // burgundy
    imagefilledrectangle($img, 140, 30, 190, 70, imagecolorallocate($img, 200, 169, 106)); // gold
    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    imagepng($img, $path);
    imagedestroy($img);

    return $path;
}

it('extracts the dominant palette of a logo with GD, ignoring the white background', function () {
    $palette = app(ExtractBrandPalette::class)->handle(ws_fake_logo());

    expect($palette)->not->toBeEmpty()
        ->and(Color::distance($palette[0], '#7A1C34'))->toBeLessThan(30)
        ->and(collect($palette)->contains(fn ($c) => Color::distance($c, '#C8A96A') < 30))->toBeTrue()
        ->and(collect($palette)->contains(fn ($c) => Color::distance($c, '#FFFFFF') < 20))->toBeFalse();
});

it('rejects files that are not images', function () {
    $path = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($path, 'not an image');
    app(ExtractBrandPalette::class)->handle($path);
})->throws(DomainException::class);

it('proposes three luxury theme presets from the logo palette', function () {
    $kit = app(GenerateBrandKit::class)->handle(ws_fake_logo());

    expect($kit['presets'])->toHaveCount(3)
        ->and(collect($kit['presets'])->pluck('key')->all())->toBe(['maison', 'atelier', 'noir']);
    foreach ($kit['presets'] as $preset) {
        expect(Color::isHex($preset['primary']))->toBeTrue()
            ->and($preset)->toHaveKeys(['accent', 'ink', 'background', 'font_display', 'font_body', 'button_style']);
    }
});

it('saves tenant branding and a per-branch override that inherits the rest', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);

    app(UpdateBranding::class)->handle($tenant, ['primary' => '#7A1C34', 'button_style' => 'rounded', 'social' => ['instagram' => 'https://instagram.com/maison', 'evil' => 'x'], 'unknown' => 'x']);
    expect($tenant->fresh()->brand('primary'))->toBe('#7A1C34')
        ->and($tenant->fresh()->branding)->not->toHaveKey('unknown')
        ->and($tenant->fresh()->brand('social'))->toBe(['instagram' => 'https://instagram.com/maison']);

    $branch = ws_main_branch();
    app(UpdateBranding::class)->handle($tenant, ['primary' => '#1E1620', 'accent' => null], $branch);
    expect($branch->fresh()->branding)->toBe(['primary' => '#1E1620'])
        ->and($tenant->fresh()->brand('primary'))->toBe('#7A1C34');
});

it('refuses invalid colours', function () {
    $tenant = createTenant();
    asTenant($tenant);
    app(UpdateBranding::class)->handle($tenant, ['primary' => 'red; background:url(x)']);
})->throws(DomainException::class);

it('generates the brand kit and applies a preset in one click from the branding page', function () {
    Storage::fake('public');
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    Storage::disk('public')->put('branding/'.$tenant->id.'/logo.png', file_get_contents(ws_fake_logo()));
    app(UpdateBranding::class)->handle($tenant, ['logo_path' => 'branding/'.$tenant->id.'/logo.png']);

    $page = Livewire::test(Branding::class)
        ->callAction(TestAction::make('generateKit')->schemaComponent('identity'))
        ->assertSet('kit.presets.0.key', 'maison');

    $page->call('applyPreset', 2)->assertSet('data.font_display', 'Bodoni Moda')
        ->call('save')->assertHasNoErrors();

    expect($tenant->fresh()->brand('font_display'))->toBe('Bodoni Moda');
})->skip(! class_exists(TestAction::class), 'TestAction helper unavailable');
