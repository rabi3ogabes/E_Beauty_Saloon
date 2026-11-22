<?php

namespace App\Http\Controllers\Site\Booking;

use App\Domain\Booking\Availability\AvailabilityEngine;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Service;
use App\Domain\Catalog\Models\ServiceCategory;
use App\Domain\Team\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** JSON shapes for the public booking flow (branches, catalog, staff, date strip). */
class PublicCatalog
{
    public function branches(): Collection
    {
        return Branch::query()->active()->where('booking_enabled', true)->orderBy('sort')->get()
            ->map(fn (Branch $b) => [
                'id' => $b->id, 'slug' => $b->slug, 'name' => $b->name, 'address' => $b->address, 'area' => $b->area,
                'image' => $b->cover_path ? asset('storage/'.$b->cover_path) : null, 'timezone' => $b->timezone,
            ])->values();
    }

    public function catalog(Branch $branch): array
    {
        $services = Service::query()->active()->where('online_bookable', true)
            ->whereHas('branches', fn ($q) => $q->withoutGlobalScopes()->where('branches.id', $branch->id)->where('branch_service.is_active', true))
            ->with(['variants', 'addons', 'branches'])->orderBy('sort')->get();
        $categories = ServiceCategory::query()->where('is_active', true)->orderBy('sort')->get();

        $staff = app(AvailabilityEngine::class)->capableStaff($branch, $services->pluck('id')->all())->load(['services' => fn ($q) => $q->select('services.id')]);

        return [
            'branch' => ['id' => $branch->id, 'slug' => $branch->slug, 'name' => $branch->name, 'timezone' => $branch->timezone],
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id, 'slug' => $c->slug, 'name' => $c->name,
                'services' => $services->where('service_category_id', $c->id)->values()->map(fn ($s) => $this->service($s, $branch))->all(),
            ])->filter(fn ($c) => $c['services'] !== [])->values()->all(),
            'uncategorized' => $services->whereNull('service_category_id')->values()->map(fn ($s) => $this->service($s, $branch))->all(),
            'staff' => $staff->map(fn (Staff $s) => [
                'id' => $s->id, 'name' => $s->name, 'title' => $s->title, 'color' => $s->color,
                'photo' => $s->photo_path ? asset('storage/'.$s->photo_path) : null,
                'initials' => mb_strtoupper(mb_substr((string) $s->name, 0, 1)),
                'rating' => $s->ratings_count ? round((float) $s->rating, 1) : null, 'ratings_count' => (int) $s->ratings_count,
                'service_ids' => $s->services->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ])->values()->all(),
            'dates' => $this->dates($branch),
            'currency' => tenant()?->currency ?? 'QAR',
        ];
    }

    protected function service(Service $s, Branch $branch): array
    {
        $price = $s->priceAt($branch);

        return [
            'id' => $s->id, 'slug' => $s->slug, 'name' => $s->name, 'description' => $s->description,
            'duration' => $s->durationAt($branch), 'price' => $price, 'price_label' => money($price),
            'deposit' => $s->depositFor($price), 'image' => $s->image_path ? asset('storage/'.$s->image_path) : null,
            'variants' => $s->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'duration' => (int) $v->duration_minutes, 'price' => (int) $v->price, 'price_label' => money((int) $v->price)])->values()->all(),
            'addons' => $s->addons->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'duration' => (int) $a->duration_minutes, 'price' => (int) $a->price, 'price_label' => money((int) $a->price)])->values()->all(),
        ];
    }

    /** Date strip: today .. max days ahead (capped at 60), closed days flagged from hours + holidays. */
    public function dates(Branch $branch): array
    {
        $branch->load('holidays');
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $days = min(60, BookingSettings::get('max_days_ahead'));
        $out = [];
        for ($i = 0; $i <= $days; $i++) {
            $d = $today->addDays($i);
            $out[] = [
                'date' => $d->toDateString(), 'dow' => $d->translatedFormat('D'), 'day' => $d->format('j'),
                'month' => $d->translatedFormat('M'), 'closed' => $branch->hoursFor($d) === null, 'label' => $d->translatedFormat('l j F'),
            ];
        }

        return $out;
    }
}
