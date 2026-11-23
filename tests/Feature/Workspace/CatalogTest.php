<?php

require_once __DIR__.'/WorkspaceHelpers.php';

use App\Domain\Catalog\Actions\ImportServiceTemplates;
use App\Domain\Catalog\Actions\SetBranchServiceOverride;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Catalog\Templates\ServiceTemplates;
use App\Domain\CRM\Models\Client;
use App\Domain\Team\Models\Staff;
use App\Domain\Workspace\Excel\ClientsImport;
use App\Domain\Workspace\Excel\ProductsImport;
use App\Domain\Workspace\Excel\ServicesImport;
use App\Domain\Workspace\Excel\SheetExport;
use App\Domain\Workspace\Excel\StaffImport;
use App\Filament\Dashboard\Resources\Products\Pages\CreateProduct;
use App\Filament\Dashboard\Resources\Services\Pages\CreateService;
use App\Filament\Dashboard\Resources\Services\Pages\EditService;
use App\Filament\Dashboard\Resources\Services\Pages\ListServices;
use App\Filament\Dashboard\Resources\Services\RelationManagers\BranchOverridesRelationManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(fn () => Storage::fake('local'));

/** Writes rows to a real .xlsx on the local disk and returns its relative path. */
function ws_xlsx(array $headings, array $rows, string $name = 'import.xlsx'): string
{
    Excel::store(new SheetExport($headings, $rows), 'imports/'.$name, 'local');

    return 'imports/'.$name;
}

it('same service shows different price/duration in two branches', function () {
    $tenant = createTenant(branches: 2);
    ws_owner_session($this, $tenant);
    $pearl = ws_branch('pearl');
    $service = Service::create(['name' => ['en' => 'Blow-dry'], 'slug' => 'blow-dry', 'duration_minutes' => 45, 'price' => 12000]);

    app(SetBranchServiceOverride::class)->handle($service, $pearl, true, 15000, 60);

    expect($service->priceAt(ws_main_branch()))->toBe(12000)
        ->and($service->durationAt(ws_main_branch()))->toBe(45)
        ->and($service->fresh()->priceAt($pearl))->toBe(15000)
        ->and($service->fresh()->durationAt($pearl))->toBe(60);

    app(SetBranchServiceOverride::class)->handle($service, $pearl, false);
    expect($service->fresh()->isActiveAt($pearl))->toBeFalse()->and($service->fresh()->priceAt($pearl))->toBe(12000);
});

it('edits the branch override from the service relation manager', function () {
    $tenant = createTenant(branches: 2);
    ws_owner_session($this, $tenant);
    $pearl = ws_branch('pearl');
    $service = Service::create(['name' => ['en' => 'Blow-dry'], 'slug' => 'blow-dry', 'duration_minutes' => 45, 'price' => 12000]);

    Livewire::test(BranchOverridesRelationManager::class, ['ownerRecord' => $service, 'pageClass' => EditService::class])
        ->callTableAction('addOverride', data: ['branch_id' => $pearl->id, 'is_active' => true, 'price' => '175.50', 'duration_minutes' => 50])
        ->assertHasNoTableActionErrors();

    expect($service->fresh()->priceAt($pearl))->toBe(17550)->and($service->fresh()->durationAt($pearl))->toBe(50);
});

it('creates a service with variants, add-ons and a percent deposit stored in basis points', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $cat = ServiceCategory::create(['name' => ['en' => 'Hair'], 'slug' => 'hair']);

    Livewire::test(CreateService::class)
        ->fillForm([
            'name' => ['en' => 'Balayage', 'ar' => 'بالياج'], 'service_category_id' => $cat->id, 'gender' => 'women', 'duration_minutes' => 180,
            'price' => '950', 'deposit_type' => 'percent', 'deposit_percent' => 20, 'resource_types' => ['chair', 'wash_basin'], 'rebook_after_days' => 90,
            'variants' => [['name' => ['en' => 'Long'], 'duration_minutes' => 210, 'price' => '1150']],
            'addons' => [['name' => ['en' => 'Olaplex'], 'duration_minutes' => 15, 'price' => '120']],
        ])
        ->call('create')->assertHasNoFormErrors();

    $service = Service::where('slug', 'balayage')->firstOrFail();
    expect($service->price)->toBe(95000)
        ->and($service->deposit_type)->toBe('percent')->and($service->deposit_value)->toBe(2000)
        ->and($service->depositFor(95000))->toBe(19000)
        ->and($service->variants()->first()->price)->toBe(115000)
        ->and($service->addons()->first()->price)->toBe(12000)
        ->and($service->resource_types)->toBe(['chair', 'wash_basin']);
});

it('creates a product with variants (SKU, barcode, swatch, cost, price)', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => ['en' => 'Lip Velvet'], 'usage' => 'retail', 'is_active' => true,
            'variants' => [['name' => ['en' => 'Rose Nude'], 'sku' => 'LV-ROSE', 'barcode' => '123', 'swatch' => '#C08081', 'cost' => '40', 'price' => '95', 'is_default' => true]]])
        ->call('create')->assertHasNoFormErrors();

    $variant = ProductVariant::where('sku', 'LV-ROSE')->firstOrFail();
    expect($variant->price)->toBe(9500)->and($variant->cost)->toBe(4000)->and($variant->swatch)->toBe('#C08081')
        ->and(Product::firstOrFail()->slug)->toBe('lip-velvet');
});

it('quick-imports bilingual service templates with QAR prices, idempotently', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);

    $created = app(ImportServiceTemplates::class)->handle(['hair', 'lashes', 'bogus']);
    expect($created)->toBe(count(ServiceTemplates::all()['hair']) + count(ServiceTemplates::all()['lashes']))
        ->and(ServiceCategory::count())->toBe(2)
        ->and(Service::where('slug', 'balayage')->first()->getTranslation('name', 'ar'))->toBe('بالياج')
        ->and(app(ImportServiceTemplates::class)->handle(['hair']))->toBe(0);
});

it('imports services from Excel and reports invalid rows one by one', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    Service::create(['name' => ['en' => 'Blow-dry'], 'slug' => 'blow-dry', 'duration_minutes' => 30, 'price' => 9000]);

    $path = ws_xlsx(ServicesImport::columns(), [
        ['name_en' => 'Blow-dry', 'name_ar' => 'سشوار', 'category' => 'Hair', 'duration_minutes' => 45, 'price' => '120.00', 'gender' => 'women', 'online_bookable' => 'yes'],
        ['name_en' => 'Gel Manicure', 'category' => 'Nails', 'duration_minutes' => 60, 'price' => '150', 'gender' => 'women'],
        ['name_en' => '', 'duration_minutes' => 60, 'price' => '10'],
        ['name_en' => 'Broken', 'duration_minutes' => 'abc', 'price' => '-5', 'gender' => 'robots'],
    ]);

    $import = new ServicesImport;
    Excel::import($import, $path, 'local');

    expect($import->created)->toBe(1)->and($import->updated)->toBe(1)
        ->and(array_keys($import->errors))->toBe([4, 5])
        ->and(count($import->errors[5]))->toBeGreaterThanOrEqual(3)
        ->and(Service::where('slug', 'blow-dry')->first()->price)->toBe(12000)
        ->and(Service::where('slug', 'blow-dry')->first()->duration_minutes)->toBe(45)
        ->and(Service::where('slug', 'gel-manicure')->first()->category->slug)->toBe('nails');
});

it('runs the Excel import from the services list header action and downloads the template', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);
    $path = ws_xlsx(ServicesImport::columns(), [['name_en' => 'Brow Lamination', 'duration_minutes' => 45, 'price' => '200']], 'svc.xlsx');

    Livewire::test(ListServices::class)
        ->callAction(TestAction::make('importExcel')->arguments([]), data: ['file' => UploadedFile::fake()->createWithContent('svc.xlsx', Storage::disk('local')->get($path))])
        ->assertHasNoActionErrors()
        ->callAction('downloadTemplate')
        ->assertFileDownloaded('services-template.xlsx');

    expect(Service::where('slug', 'brow-lamination')->exists())->toBeTrue();
});

it('round-trips products, clients and staff through Excel', function () {
    $tenant = createTenant();
    ws_owner_session($this, $tenant);

    $products = new ProductsImport;
    Excel::import($products, ws_xlsx(ProductsImport::columns(), [ProductsImport::example(), ['name_en' => 'Repair Shampoo', 'sku' => 'KER-SH-1L', 'price' => '390']], 'p.xlsx'), 'local');
    expect($products->created)->toBe(2)->and(Product::count())->toBe(1)->and(ProductVariant::count())->toBe(2);

    $clients = new ClientsImport;
    Excel::import($clients, ws_xlsx(ClientsImport::columns(), [ClientsImport::example(), ['name' => 'No phone']], 'c.xlsx'), 'local');
    expect($clients->created)->toBe(1)->and(array_keys($clients->errors))->toBe([3])
        ->and(Client::first()->phone)->toBe('+97455123456')->and(Client::first()->tags()->count())->toBe(2);

    $staff = new StaffImport;
    Excel::import($staff, ws_xlsx(StaffImport::columns(), [StaffImport::example()], 's.xlsx'), 'local');
    expect($staff->created)->toBe(1)->and(Staff::first()->branches()->first()?->slug)->toBe('main');

    expect(count(ServicesImport::exportRows()))->toBe(0)->and(ClientsImport::exportRows()[0]['phone'])->toBe('+97455123456');
});
