<?php

namespace App\Domain\POS\Http\Controllers;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Service;
use App\Domain\POS\Actions\SyncOfflineSales;
use App\Domain\POS\Models\Register;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** PWA shell (manifest, service worker, icon), offline catalog and the offline sync endpoint. */
class PosAppController extends Controller
{
    public function serviceWorker(): Response
    {
        return response(view('pos.sw', ['version' => $this->assetVersion()])->render(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/pos/',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => (tenant()?->name ?? config('salon.name')).' · POS',
            'short_name' => 'POS',
            'start_url' => '/pos',
            'scope' => '/pos/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#FAF7F2',
            'theme_color' => '#1C1A17',
            'icons' => [['src' => route('pos.icon'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable']],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    public function icon(): Response
    {
        $letter = mb_strtoupper(mb_substr((string) (tenant()?->name ?? 'L'), 0, 1));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="112" fill="#1C1A17"/>'
            .'<circle cx="256" cy="256" r="170" fill="none" stroke="#C8A96A" stroke-width="10"/>'
            .'<text x="256" y="318" font-family="Georgia,serif" font-size="190" text-anchor="middle" fill="#C8A96A">'.e($letter).'</text></svg>';

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400']);
    }

    /** Lightweight catalog cached by the service worker for offline selling. */
    public function catalog(Request $request): JsonResponse
    {
        $this->authorizePos($request);
        $branchId = (int) $request->query('branch') ?: Register::query()->value('branch_id');

        $services = Service::query()->active()->with('branches')->orderBy('sort')->get()
            ->filter(fn (Service $s) => $branchId === 0 || $s->branches->isEmpty() || $s->branches->contains('id', $branchId))
            ->map(fn (Service $s) => ['type' => 'service', 'id' => $s->id, 'name' => (string) $s->name,
                'price' => $s->priceAt($s->branches->firstWhere('id', $branchId))])->values();
        $products = ProductVariant::query()->with('product')->whereHas('product', fn ($q) => $q->where('is_active', true))->get()
            ->map(fn (ProductVariant $v) => ['type' => 'product', 'id' => $v->id, 'name' => $v->displayName(), 'price' => $v->effectivePrice(), 'barcode' => $v->barcode, 'sku' => $v->sku])->values();

        return response()->json([
            'currency' => tenant()->currency ?? 'QAR',
            'branch_id' => $branchId,
            'generated_at' => now()->toIso8601String(),
            'items' => $services->concat($products)->values(),
        ]);
    }

    /** Replays queued offline sales; dedupes by idempotency key; reports stock conflicts. */
    public function sync(Request $request, SyncOfflineSales $sync): JsonResponse
    {
        $this->authorizePos($request);
        $data = $request->validate([
            'sales' => ['required', 'array', 'max:200'],
            'sales.*.idempotency_key' => ['required', 'string', 'max:64'],
            'sales.*.branch_id' => ['required', 'integer'],
            'sales.*.lines' => ['required', 'array', 'min:1'],
            'sales.*.payments' => ['required', 'array'],
        ]);

        return response()->json($sync->handle($request->input('sales', $data['sales']), $request->user()));
    }

    protected function authorizePos(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->tenant_id === tenant()?->id && $user->can('pos.access'), 403);
    }

    protected function assetVersion(): string
    {
        $manifest = public_path('build/manifest.json');

        return is_file($manifest) ? substr(md5_file($manifest), 0, 10) : 'dev';
    }
}
