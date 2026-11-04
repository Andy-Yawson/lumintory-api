<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch\Job;
use App\Services\Pay\DispatchPay;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The shop owner's settlement statement: every delivered rider order in a period, what the customer paid, what Zinnvy took,
 * and what reached the shop's wallet. Workspace administrators only.
 */
class DispatchStatementController extends Controller
{
    public function show(Request $request)
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'format' => 'nullable|in:json,csv']);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now())->endOfDay();
        abort_if($from->diffInDays($to) > 400, 422, 'Pick a range of up to 13 months.');

        $jobs = Job::with('order')->where('tenant_id', Auth::user()->tenant_id)->where('status', 'delivered')->whereBetween('delivered_at', [$from, $to])->orderBy('delivered_at')->get();
        $rows = $jobs->map(fn (Job $j) => [
            'date' => $j->delivered_at->toDateString(),
            'delivery' => $j->code,
            'order' => $j->order?->number,
            'customer_paid' => $j->total() / 100,
            'goods' => $j->goods_amount / 100,
            'delivery_fee' => $j->delivery_fee / 100,            // the customer's delivery fee: paid to the rider and Zinnvy, never to the shop
            'zinnvy_commission' => $j->goods_commission / 100,   // taken from the goods value (0 unless Zinnvy sets one)
            'you_received' => $j->ownerShare() / 100,
        ])->values();

        $totals = [
            'deliveries' => $rows->count(), 'goods' => $rows->sum('goods'), 'delivery_fees' => $rows->sum('delivery_fee'),
            'zinnvy_commission' => $rows->sum('zinnvy_commission'), 'you_received' => $rows->sum('you_received'),
        ];

        if (($data['format'] ?? 'json') === 'csv') {
            $name = 'settlement-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

            return response()->streamDownload(function () use ($rows, $totals) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Date', 'Delivery', 'Order', 'Customer paid', 'Goods', 'Delivery fee (to rider and Zinnvy)', 'Zinnvy commission on goods', 'You received']);
                foreach ($rows as $r) {
                    fputcsv($out, [$r['date'], $r['delivery'], $r['order'], $r['customer_paid'], $r['goods'], $r['delivery_fee'], $r['zinnvy_commission'], $r['you_received']]);
                }
                fputcsv($out, ['Total', $totals['deliveries'].' deliveries', '', $rows->sum('customer_paid'), $totals['goods'], $totals['delivery_fees'], $totals['zinnvy_commission'], $totals['you_received']]);
                fclose($out);
            }, $name, ['Content-Type' => 'text/csv']);
        }

        // What has actually been withdrawn from the wallet in the period, from Pay (null if Pay can't be reached).
        $withdrawn = null;
        if (DispatchPay::configured()) {
            try {
                $withdrawn = collect(app(DispatchPay::class)->withdrawals('shop:'.Auth::user()->tenant_id)['data'] ?? [])
                    ->filter(fn ($w) => $w['status'] === 'paid' && Carbon::parse($w['paid_at'] ?? $w['created_at'])->between($from, $to))->sum('amount') / 100;
            } catch (\Throwable) {
                $withdrawn = null;
            }
        }

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows, 'totals' => $totals + ['withdrawn' => $withdrawn]]);
    }
}
