<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Shop;
use App\Models\ShopService;
use App\Services\ImageOptimizer;
use App\Services\Shop\BookingService;
use App\Services\Shop\BookingSlots;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The owner's side of bookings: services, availability and the bookings themselves. */
class BookingController extends Controller
{
    private function shop(): Shop
    {
        $tenant = Auth::user()->tenant;

        return Shop::firstOrCreate(['tenant_id' => $tenant->id], ['slug' => Shop::suggestSlug($tenant->name), 'enabled' => false, 'in_marketplace' => true, 'pickup' => true, 'delivery' => false]);
    }

    private function admin(): void
    {
        abort_unless(Auth::user()->role === 'Administrator', 403, 'Only a workspace Administrator can change services and availability.');
    }

    // ---------------------------------------------------------------- availability

    public function settings()
    {
        $shop = $this->shop();

        return response()->json([
            'bookings_enabled' => $shop->bookings_enabled,
            'hours' => BookingSlots::hours($shop),
            'blocked_dates' => array_values($shop->booking_blocked_dates ?? []),
            'notice_hours' => $shop->booking_notice_hours,
            'max_days' => $shop->booking_max_days,
            'buffer_minutes' => $shop->booking_buffer_minutes,
            'qualifies' => Shop::tenantQualifies($shop->tenant),
            'shop_live' => $shop->isLive(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $this->admin();
        $shop = $this->shop();
        $data = $request->validate([
            'bookings_enabled' => 'sometimes|boolean',
            'hours' => 'sometimes|array',
            'hours.*' => 'array|max:4',
            'hours.*.*' => 'array|size:2',
            'hours.*.*.*' => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'blocked_dates' => 'sometimes|array|max:200',
            'blocked_dates.*' => 'date_format:Y-m-d',
            'notice_hours' => 'sometimes|integer|between:0,336',
            'max_days' => 'sometimes|integer|between:1,180',
            'buffer_minutes' => 'sometimes|integer|between:0,240',
        ]);
        if (($data['bookings_enabled'] ?? false) && ! Shop::tenantQualifies($shop->tenant)) {
            return response()->json(['message' => 'Bookings are part of the Pro plan.'], 403);
        }
        if (isset($data['hours'])) {
            $hours = [];
            foreach (BookingSlots::DAYS as $d) {
                $hours[$d] = collect($data['hours'][$d] ?? [])->filter(fn ($r) => $r[0] < $r[1])->values()->all();
            }
            $shop->booking_hours = $hours;
        }
        if (isset($data['blocked_dates'])) {
            $shop->booking_blocked_dates = collect($data['blocked_dates'])->unique()->sort()->values()->all();
        }
        foreach (['bookings_enabled' => 'bookings_enabled', 'notice_hours' => 'booking_notice_hours', 'max_days' => 'booking_max_days', 'buffer_minutes' => 'booking_buffer_minutes'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $shop->{$col} = $data[$in];
            }
        }
        $shop->save();

        return $this->settings();
    }

    // ---------------------------------------------------------------- services

    public function services()
    {
        return response()->json(['data' => ShopService::where('shop_id', $this->shop()->id)->orderBy('position')->orderBy('id')->get()]);
    }

    private function serviceRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0',
            'price_from' => 'boolean',
            'duration_minutes' => 'required|integer|between:15,720',
            'location' => ['required', Rule::in(ShopService::LOCATIONS)],
            'travel_note' => 'nullable|string|max:200',
            'product_ids' => 'nullable|array|max:50',
            'product_ids.*' => 'integer',
            'active' => 'boolean',
        ];
    }

    private function cleanProducts(array $data): array
    {
        if (isset($data['product_ids'])) {
            $data['product_ids'] = \App\Models\Product::where('tenant_id', Auth::user()->tenant_id)->whereIn('id', $data['product_ids'])->pluck('id')->all() ?: null;
        }

        return $data;
    }

    public function storeService(Request $request)
    {
        $this->admin();
        $shop = $this->shop();
        $data = $this->cleanProducts($request->validate($this->serviceRules()));
        $service = new ShopService($data + ['shop_id' => $shop->id, 'position' => (int) ShopService::where('shop_id', $shop->id)->max('position') + 1]);
        $service->tenant_id = $shop->tenant_id;
        $service->save();

        return response()->json($service, 201);
    }

    public function updateService(Request $request, int $id)
    {
        $this->admin();
        $service = ShopService::where('shop_id', $this->shop()->id)->findOrFail($id);
        $service->update($this->cleanProducts($request->validate(collect($this->serviceRules())->map(fn ($r) => is_array($r) ? array_merge(['sometimes'], $r) : 'sometimes|'.$r)->all())));

        return response()->json($service->fresh());
    }

    public function destroyService(int $id)
    {
        $this->admin();
        $service = ShopService::where('shop_id', $this->shop()->id)->findOrFail($id);
        if ($service->image_path) {
            rescue(fn () => Storage::disk('media')->delete($service->image_path), report: true);
        }
        $service->delete(); // past bookings keep their snapshot (name, price, time)

        return response()->noContent();
    }

    public function serviceImage(Request $request, int $id)
    {
        $this->admin();
        $service = ShopService::where('shop_id', $this->shop()->id)->findOrFail($id);
        $request->validate(['image' => 'required|file|mimes:jpg,jpeg,png,webp|max:15360']);
        $file = $request->file('image');
        $opt = ImageOptimizer::optimize($file->getContent(), 1600);
        $path = "tenants/{$service->tenant_id}/services/{$service->id}-".Str::uuid().'.'.($opt['ext'] ?? $file->extension());
        try {
            Storage::disk('media')->put($path, $opt['bytes'] ?? $file->getContent(), ['ContentType' => $opt['mime'] ?? $file->getMimeType()]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Image storage is unavailable right now. Please try again shortly.'], 503);
        }
        $old = $service->image_path;
        $service->update(['image_path' => $path]);
        if ($old) {
            rescue(fn () => Storage::disk('media')->delete($old), report: true);
        }

        return response()->json($service->fresh());
    }

    // ---------------------------------------------------------------- bookings

    public function index(Request $request)
    {
        $shop = $this->shop();
        $q = Booking::where('shop_id', $shop->id);
        $view = $request->query('view', 'upcoming');
        match ($view) {
            'requested' => $q->where('status', 'requested')->orderBy('starts_at'),
            'upcoming' => $q->whereIn('status', ['requested', 'confirmed'])->where('ends_at', '>=', now()->startOfDay())->orderBy('starts_at'),
            'past' => $q->where(fn ($w) => $w->where('ends_at', '<', now()->startOfDay())->orWhereIn('status', ['completed', 'no_show']))->whereNotIn('status', ['cancelled', 'declined'])->orderByDesc('starts_at'),
            'cancelled' => $q->whereIn('status', ['cancelled', 'declined'])->orderByDesc('starts_at'),
            default => $q->orderBy('starts_at'),
        };
        if ($request->filled('from') && $request->filled('to')) {
            $q->whereBetween('starts_at', [CarbonImmutable::parse($request->query('from'))->startOfDay(), CarbonImmutable::parse($request->query('to'))->endOfDay()]);
        }

        return response()->json([
            'data' => $q->limit(200)->get(),
            'counts' => [
                'requested' => Booking::where('shop_id', $shop->id)->where('status', 'requested')->count(),
                'today' => Booking::where('shop_id', $shop->id)->where('status', 'confirmed')->whereDate('starts_at', today())->count(),
            ],
        ]);
    }

    public function update(Request $request, int $id, BookingService $bookings)
    {
        $booking = Booking::where('shop_id', $this->shop()->id)->findOrFail($id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'declined', 'cancelled', 'completed', 'no_show'])],
            'note' => 'nullable|string|max:300',
        ]);

        return response()->json($bookings->setStatus($booking, $data['status'], $data['note'] ?? null));
    }

    public function reschedule(Request $request, int $id, BookingService $bookings)
    {
        $booking = Booking::where('shop_id', $this->shop()->id)->findOrFail($id);
        $data = $request->validate(['starts_at' => 'required|date|after:now', 'note' => 'nullable|string|max:300']);
        abort_if(in_array($booking->status, ['completed', 'no_show'], true), 422, 'This booking is already finished.');

        return response()->json($bookings->reschedule($booking, CarbonImmutable::parse($data['starts_at']), $data['note'] ?? null));
    }
}
