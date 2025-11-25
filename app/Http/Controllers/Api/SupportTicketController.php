<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = SupportTicket::with(['user', 'assignee'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at');

        // optionally filter by status / priority later
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $query->paginate(20);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;

        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'nullable|in:low,medium,high',
            'category' => 'nullable|string|max:100',
        ]);

        $ticket = SupportTicket::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'subject' => $data['subject'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? 'low',
            'category' => $data['category'] ?? null,
            'status' => 'open',
            'last_reply_at' => now(),
        ]);

        SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $data['description'],
        ]);

        // TODO: dispatch notification to internal support email/Slack

        return response()->json(['success' => true, 'ticket' => $ticket], 201);
    }

    public function show(SupportTicket $ticket)
    {
        $this->authorizeTenant($ticket);
        $ticket->load(['user', 'assignee', 'messages.user']);
        return $ticket;
    }

    public function addMessage(Request $request, SupportTicket $ticket)
    {
        $this->authorizeTenant($ticket);

        $data = $request->validate([
            'message' => 'required|string',
        ]);

        $msg = SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'message' => $data['message'],
        ]);

        $ticket->update([
            'last_reply_at' => now(),
            'status' => $ticket->status === 'resolved' ? 'open' : $ticket->status,
        ]);

        // Notify assignee / creator here

        return response()->json(['success' => true, 'message' => $msg]);
    }

    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        $this->authorizeTenant($ticket);

        // Only Admin / internal support
        if (!in_array($request->user()->role, ['Administrator', 'Support'])) {
            abort(403, 'Not allowed to change status.');
        }

        $data = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $ticket->update([
            'status' => $data['status'],
            'assigned_to' => $data['assigned_to'] ?? $ticket->assigned_to,
        ]);

        return response()->json(['success' => true, 'ticket' => $ticket]);
    }

    public function analytics(Request $request)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id;

        // (Optional) only Pro or Custom
        $plan = strtolower($user->tenant->plan ?? 'basic');
        if (!in_array($plan, ['pro', 'custom'])) {
            return response()->json([
                'success' => false,
                'message' => 'Support analytics is available on Pro plans only.'
            ], 403);
        }

        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $startOfPrev6 = $now->copy()->subMonths(5)->startOfMonth(); // 6-month window

        // Base query
        $base = SupportTicket::where('tenant_id', $tenantId);

        // Tickets this month
        $ticketsThisMonth = (clone $base)
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        // Resolved this month
        $resolvedThisMonth = (clone $base)
            ->where('status', 'resolved')
            ->where('updated_at', '>=', $startOfMonth)
            ->count();

        // Status breakdown
        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // Average first response time:
        // (difference between created_at and first message from someone other than creator)
        $tickets = (clone $base)
            ->with([
                'messages' => function ($q) {
                    $q->orderBy('created_at', 'asc');
                }
            ])
            ->get();

        $firstResponseSeconds = [];
        $resolutionSeconds = [];

        foreach ($tickets as $ticket) {
            // resolution time: created_at -> when status first became "resolved"
            if ($ticket->status === 'resolved' || $ticket->status === 'closed') {
                // for simplicity, use difference between created_at and updated_at
                $resolutionSeconds[] = $ticket->created_at->diffInSeconds($ticket->updated_at);
            }

            // first response time
            $creatorId = $ticket->user_id;
            $firstResponse = $ticket->messages
                ->first(function ($msg) use ($creatorId) {
                    return $msg->user_id !== $creatorId;
                });

            if ($firstResponse) {
                $firstResponseSeconds[] = $ticket->created_at->diffInSeconds($firstResponse->created_at);
            }
        }

        $avgFirstResponseMinutes = count($firstResponseSeconds)
            ? round(array_sum($firstResponseSeconds) / count($firstResponseSeconds) / 60, 1)
            : null;

        $avgResolutionHours = count($resolutionSeconds)
            ? round(array_sum($resolutionSeconds) / count($resolutionSeconds) / 3600, 1)
            : null;

        // Monthly trend (last 6 months)
        $monthly = (clone $base)
            ->where('created_at', '>=', $startOfPrev6)
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as ym, COUNT(*) as count')
            ->groupBy('ym')
            ->orderBy('ym', 'asc')
            ->get();

        // normalize months
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->format('Y-m');
            $found = $monthly->firstWhere('ym', $month);
            $trend[] = [
                'month' => $month,
                'count' => $found ? $found->count : 0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'tickets_this_month' => $ticketsThisMonth,
                'resolved_this_month' => $resolvedThisMonth,
                'by_status' => $byStatus,
                'avg_first_response_m' => $avgFirstResponseMinutes,
                'avg_resolution_h' => $avgResolutionHours,
                'trend' => $trend,
            ],
        ]);
    }


    protected function authorizeTenant(SupportTicket $ticket)
    {
        if (auth()->user()->tenant_id !== $ticket->tenant_id) {
            abort(403, 'Forbidden');
        }
    }
}
