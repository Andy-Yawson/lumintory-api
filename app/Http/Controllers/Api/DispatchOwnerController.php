<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch\Job;
use App\Models\ShopOrder;
use App\Services\Dispatch\DispatchService;
use App\Services\Pay\DispatchPay;
use App\Services\Pay\PayRejected;
use App\Services\Pay\PayUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The shop owner's side of a delivery: ask for a rider, watch it, and see the money in their wallet. */
class DispatchOwnerController extends Controller
{
    public function __construct(private DispatchService $dispatch, private DispatchPay $pay) {}

    public static function jobPayload(Job $job): array
    {
        return [
            'id' => $job->id,
            'code' => $job->code,
            'status' => $job->status,
            'pay_status' => $job->pay_status,
            'rider' => $job->rider && $job->status !== 'searching' ? ['name' => $job->rider->name, 'phone' => $job->rider->phone, 'vehicle' => $job->rider->vehicle, 'plate' => $job->rider->plate] : null,
            'delivery_fee' => $job->delivery_fee / 100,
            'goods' => $job->goods_amount / 100,
            'you_receive' => $job->ownerShare() / 100,
            'failure_reason' => $job->failure_reason,
            'assigned_at' => $job->assigned_at?->toIso8601String(),
            'picked_up_at' => $job->picked_up_at?->toIso8601String(),
            'delivered_at' => $job->delivered_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    public function request(int $id)
    {
        abort_unless(in_array(Auth::user()->role, ['Administrator', 'Sales'], true), 403);
        $order = ShopOrder::findOrFail($id);

        return response()->json(self::jobPayload($this->dispatch->request($order)->load('rider')), 201);
    }

    public function cancel(int $id)
    {
        $job = Job::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        return response()->json(self::jobPayload($this->dispatch->cancel($job)->load('rider')));
    }

    // ------------------------------------------------------------------ wallet (workspace admins)

    private function ref(): string
    {
        return 'shop:'.Auth::user()->tenant_id;
    }

    private function guard(callable $fn)
    {
        abort_unless(DispatchPay::configured(), 503, 'Wallets are not switched on yet.');
        try {
            return $fn();
        } catch (PayRejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PayUnavailable $e) {
            report($e);

            return response()->json(['message' => 'Wallets are unavailable for a moment. Please try again.'], 503);
        }
    }

    public function wallet()
    {
        return $this->guard(fn () => response()->json($this->pay->ensureWallet($this->ref(), 'owner', Auth::user()->tenant->name)));
    }

    public function entries(Request $request)
    {
        return $this->guard(function () use ($request) {
            $this->pay->ensureWallet($this->ref(), 'owner', Auth::user()->tenant->name);

            return response()->json($this->pay->entries($this->ref(), $request->query('cursor')));
        });
    }

    public function withdrawals()
    {
        return $this->guard(function () {
            $this->pay->ensureWallet($this->ref(), 'owner', Auth::user()->tenant->name);

            return response()->json($this->pay->withdrawals($this->ref()));
        });
    }

    public function setPayout(Request $request)
    {
        $data = $request->validate(['network' => 'required|string|max:20', 'number' => 'required|string|max:20']);

        return $this->guard(function () use ($data) {
            $this->pay->ensureWallet($this->ref(), 'owner', Auth::user()->tenant->name);

            return response()->json($this->pay->setPayout($this->ref(), $data['network'], $data['number']));
        });
    }

    public function withdraw(Request $request)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:1', 'key' => 'required|string|max:60']);

        return $this->guard(fn () => response()->json($this->pay->withdraw($this->ref(), (int) round($data['amount'] * 100), 'shop'.Auth::user()->tenant_id.'-'.$data['key']), 201));
    }
}
