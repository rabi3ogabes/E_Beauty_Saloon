<?php

require_once __DIR__.'/helpers.php';

use App\Domain\AIStudio\Actions\InstallExampleLooks;
use App\Domain\AIStudio\Models\Style;
use App\Domain\AIStudio\Support\ExampleLooks;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Filament\Dashboard\Resources\Styles\Pages\ManageStyles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    $this->tenant = createTenant(slug: 'lumiere', modules: ['ai_studio', 'booking']);
});

it('shows every look and its photos on the studio page before any consent', function () {
    $style = aiStyle($this->tenant, ['reference_path' => 'ai-styles/a.webp', 'gallery' => ['ai-styles/b.webp', 'ai-styles/c.webp'], 'illustrative' => true]);

    $page = $this->get(tenantUrl($this->tenant, 'studio'))->assertOk()->assertSee(__('ai_studio.lookbook.title'));
    $look = collect($page->viewData('styles'))->firstWhere('id', $style->id);
    expect($look['images'])->toHaveCount(3)
        ->and($look['images'][0])->toEndWith('ai-styles/a.webp')
        ->and($look['thumb'])->toEndWith('ai-styles/a.webp')
        ->and($look['illustrative'])->toBeTrue()
        ->and($look['book'])->toContain('book?service=balayage')->toContain('look=style-'.$style->id);
    expect($style->imagePaths())->toBe(['ai-styles/a.webp', 'ai-styles/b.webp', 'ai-styles/c.webp']);
});

it('uses a thumbnail for the grid when one exists next to the cover', function () {
    Storage::disk('public')->put('ai-styles/x.webp', 'big');
    Storage::disk('public')->put('ai-styles/x-thumb.webp', 'small');
    $with = aiStyle($this->tenant, ['reference_path' => 'ai-styles/x.webp']);

    expect($with->thumbUrl())->toEndWith('ai-styles/x-thumb.webp');
    $with->update(['reference_path' => 'ai-styles/y.webp']);
    expect($with->fresh()->thumbUrl())->toEndWith('ai-styles/y.webp');
});

it('installs the example looks with photos, extension services and no duplicates on a second run', function () {
    asTenant($this->tenant, function () {
        ServiceCategory::create(['name' => ['en' => 'Hair'], 'slug' => 'hair']);
        Service::create(['name' => ['en' => 'Gel manicure'], 'slug' => 'gel-manicure', 'duration_minutes' => 60, 'price' => 18000]);
    });

    $first = app(InstallExampleLooks::class)->handle($this->tenant);
    $again = app(InstallExampleLooks::class)->handle($this->tenant);

    asTenant($this->tenant, function () use ($first, $again) {
        expect($first['created'])->toBe(count(ExampleLooks::all()))
            ->and($first['services'])->toBe(count(ExampleLooks::EXTENSION_SERVICES))
            ->and($again['created'])->toBe(0)->and($again['services'])->toBe(0)
            ->and(Style::count())->toBe(count(ExampleLooks::all()));

        $rouge = Style::get()->first(fn ($s) => $s->getTranslation('name', 'en') === 'Rouge noir gel');
        expect($rouge->service->slug)->toBe('gel-manicure');

        $extensions = Style::where('category', 'extensions')->get();
        expect($extensions)->toHaveCount(4)
            ->and($extensions->every(fn ($s) => $s->service !== null))->toBeTrue()
            ->and($extensions->first()->isFaceFeature())->toBeTrue();

        // Bundled photos land on the public disk, cover first, marked illustrative.
        $withPhotos = Style::whereNotNull('reference_path')->get();
        expect($withPhotos)->not->toBeEmpty();
        foreach ($withPhotos as $style) {
            expect($style->illustrative)->toBeTrue();
            foreach ($style->imagePaths() as $path) {
                Storage::disk('public')->assertExists($path);
            }
        }
    });
});

it('never replaces a salon\'s own photos unless forced', function () {
    $own = aiStyle($this->tenant, ['category' => 'nails', 'option' => 'color', 'name' => ['en' => 'Rouge noir gel'], 'reference_path' => 'ai-styles/our-work.jpg']);

    app(InstallExampleLooks::class)->handle($this->tenant);
    expect($own->fresh()->reference_path)->toBe('ai-styles/our-work.jpg')->and($own->fresh()->illustrative)->toBeFalse();

    if (is_file(base_path(ExampleLooks::SOURCE_DIR.'/rouge-noir-gel-1.webp'))) {
        app(InstallExampleLooks::class)->handle($this->tenant, force: true);
        expect($own->fresh()->reference_path)->toContain('examples/');
    }
});

it('lets the owner add gallery photos to a look from the dashboard', function () {
    $style = aiStyle($this->tenant, ['category' => 'nails', 'option' => 'color']);
    Filament\Facades\Filament::setCurrentPanel('dashboard');
    asTenant($this->tenant);
    $this->actingAs($this->tenant->owner);

    Livewire::test(ManageStyles::class)
        ->callTableAction('edit', $style, data: ['gallery' => [UploadedFile::fake()->image('one.jpg', 800, 1000), UploadedFile::fake()->image('two.jpg', 800, 1000)], 'illustrative' => false])
        ->assertHasNoTableActionErrors();

    expect($style->fresh()->gallery)->toHaveCount(2);
});

it('runs from the command line for the demo salon', function () {
    $this->artisan('studio:install-examples')->assertSuccessful();
    $this->artisan('studio:install-examples --tenant=nope')->assertSuccessful();
});
