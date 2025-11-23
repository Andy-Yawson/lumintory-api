<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $query = AuditLog::where('tenant_id', $tenantId)->latest();

        if ($request->filled('event')) {
            $query->where('event', 'like', '%' . $request->event . '%');
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', Carbon::parse($request->from));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Carbon::parse($request->to));
        }

        $logs = $query->paginate($request->get('per_page', 25));

        return response()->json($logs);
    }

    public function stats(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $from = Carbon::now()->subDays(30);

        $base = AuditLog::where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from);

        $byUser = (clone $base)
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->get();

        $byStatus = (clone $base)
            ->selectRaw('status_code, count(*) as total')
            ->groupBy('status_code')
            ->get();

        $daily = (clone $base)
            ->selectRaw('DATE(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return response()->json([
            'total_actions_30d' => $base->count(),
            'by_user' => $byUser,
            'by_status' => $byStatus,
            'daily' => $daily,
        ]);
    }
}
