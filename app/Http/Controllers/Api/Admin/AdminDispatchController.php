<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispatch\Job;
use App\Models\Dispatch\Rider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\DispatchService;
use App\Services\Dispatch\Settings;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Zinnvy staff: who rides, what is on the road, and the rules (commission, fee bands). */
class AdminDispatchController extends Controller
{
    public function __construct(private DispatchService $dispatch) {}

    public function overview()
    {
        return response()->json([
            'riders' => ['active' => Rider::where('status', 'active')->count(), 'online' => Rider::where('status', 'active')->where('online', true)->count(), 'pending_review' => Rider::where('status', 'pending_review')->count()],
            'jobs' => ['searching' => Job::where('status', 'searching')->count(), 'on_the_road' => Job::whereIn('status', ['assigned', 'picked_up'])->count(),
                'delivered_today' => Job::where('status', 'delivered')->where('delivered_at', '>=', now()->startOfDay())->count(), 'failed_today' => Job::where('status', 'failed')->where('closed_at', '>=', now()->startOfDay())->count()],
            'trip_fees_due' => Job::where('trip_fee_due', true)->count(),
        ]);
    }

    // ------------------------------------------------------------------ riders

    private function riderRow(Rider $r): array
    {
        return $r->only(['id', 'name', 'phone', 'email', 'status', 'vehicle', 'plate', 'guarantor_name', 'guarantor_phone', 'review_note', 'online', 'deliveries', 'last_seen_at', 'created_at'])
            + ['has_documents' => (bool) ($r->id_photo_path && $r->selfie_path), 'id_number' => $r->id_number, 'rating' => $r->ratingSummary()];
    }

    public function riders(Request $request)
    {
        $q = Rider::query()->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->latest('id');

        return response()->json(['data' => $q->get()->map(fn ($r) => $this->riderRow($r))]);
    }

    public function invite(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:80', 'phone' => 'required|string|max:20', 'email' => 'required|email|max:120|unique:dispatch_riders,email|unique:users,email']);
        $tenantId = Settings::get('platform_tenant_id');
        abort_unless($tenantId, 422, 'Run dispatch:setup first.');

        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(40), 'role' => 'Rider', 'first_login' => true]);
        $user->tenant_id = $tenantId;
        $user->save();
        $rider = Rider::create(['user_id' => $user->id, 'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'], 'status' => 'invited']);

        $link = rtrim((string) config('services.frontend_url'), '/').'/rider/';
        rescue(fn () => app(SmsService::class)->queueSms($tenantId, 'Zinnvy', $data['phone'], "Hi {$data['name']}, you are invited to ride with Zinnvy. Sign in with your Zinnvy account ({$data['email']}) at {$link} to get started."), report: true);

        return response()->json($this->riderRow($rider), 201);
    }

    public function updateRider(Request $request, int $id)
    {
        $rider = Rider::findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'rejected', 'pending_review'])], 'review_note' => 'nullable|string|max:190']);
        if ($data['status'] === 'active' && ! ($rider->id_photo_path && $rider->selfie_path && $rider->id_number)) {
            return response()->json(['message' => 'This rider has not submitted their documents yet.'], 422);
        }
        $rider->update(['status' => $data['status'], 'review_note' => $data['review_note'] ?? null] + (in_array($data['status'], ['suspended', 'rejected'], true) ? ['online' => false] : []));
        if ($data['status'] === 'active') {
            rescue(fn () => app(SmsService::class)->queueSms(Settings::get('platform_tenant_id'), 'Zinnvy', $rider->phone, 'You are approved to ride with Zinnvy. Open Zinnvy Rider and go online to start receiving deliveries.'), report: true);
        }

        return response()->json($this->riderRow($rider));
    }

    /** Short-lived links to the private ID photos, for the reviewer only. */
    public function documents(int $id)
    {
        $r = Rider::findOrFail($id);
        $url = fn (?string $path) => $path ? Storage::disk('media')->temporaryUrl($path, now()->addMinutes(10)) : null;

        return response()->json(['id_photo' => $url($r->id_photo_path), 'selfie' => $url($r->selfie_path)]);
    }

    /** What customers said about a rider, newest first. */
    public function ratings(int $id)
    {
        $r = Rider::findOrFail($id);

        return response()->json(['summary' => $r->ratingSummary(), 'data' => Job::with('tenant')->where('rider_id', $r->id)->whereNotNull('rating')->latest('rated_at')->limit(30)->get()
            ->map(fn (Job $j) => ['code' => $j->code, 'shop' => $j->tenant?->name, 'rating' => $j->rating, 'comment' => $j->rating_comment, 'at' => $j->rated_at?->toIso8601String()])]);
    }

    // ------------------------------------------------------------------ jobs

    public function jobs(Request $request)
    {
        $q = Job::with(['rider', 'tenant'])->when($request->query('status'), fn ($q, $v) => $v === 'open' ? $q->whereIn('status', Job::OPEN) : $q->where('status', $v))->latest('id');
        $page = $q->paginate(25);

        return response()->json(['data' => collect($page->items())->map(fn (Job $j) => [
            'id' => $j->id, 'code' => $j->code, 'status' => $j->status, 'pay_status' => $j->pay_status, 'shop' => $j->tenant?->name, 'rider' => $j->rider?->name,
            'pickup' => $j->pickup_address, 'dropoff' => $j->dropoff_address, 'total' => $j->total() / 100, 'delivery_fee' => $j->delivery_fee / 100,
            'rider_share' => $j->rider_share / 100, 'platform_share' => $j->platformShare() / 100, 'failure_reason' => $j->failure_reason, 'trip_fee_due' => $j->trip_fee_due,
            'created_at' => $j->created_at?->toIso8601String(),
        ]), 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    public function assign(Request $request, int $id)
    {
        $data = $request->validate(['rider_id' => 'required|integer|exists:dispatch_riders,id']);
        $job = $this->dispatch->assign(Job::findOrFail($id), Rider::findOrFail($data['rider_id']));

        return response()->json(['id' => $job->id, 'status' => $job->status, 'rider' => $job->rider?->name]);
    }

    // ------------------------------------------------------------------ rules

    public function settings()
    {
        return response()->json(collect(Settings::all())->except('platform_tenant_id'));
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'rider_fee_percent' => 'sometimes|numeric|between:0,100',
            'goods_commission_percent' => 'sometimes|numeric|between:0,30',
            'fee_bands' => 'sometimes|array|min:1|max:8',
            'fee_bands.*.up_to_km' => 'required_with:fee_bands|numeric|min:0.5|max:100',
            'fee_bands.*.fee' => 'required_with:fee_bands|integer|min:100|max:100000',
            'max_km' => 'sometimes|numeric|min:1|max:100',
            'offer_seconds' => 'sometimes|integer|between:30,600',
            'failed_trip_fee' => 'sometimes|integer|between:0,10000',
            'owner_hold_minutes' => 'sometimes|integer|between:0,10080',
            'rider_hold_minutes' => 'sometimes|integer|between:0,10080',
        ]);
        if (isset($data['fee_bands'])) {
            $data['fee_bands'] = collect($data['fee_bands'])->sortBy('up_to_km')->values()->map(fn ($b) => ['up_to_km' => (float) $b['up_to_km'], 'fee' => (int) $b['fee']])->all();
        }
        foreach ($data as $key => $value) {
            Settings::put($key, $value);
        }

        return $this->settings();
    }
}
