<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\MailHelper;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

class AdminSupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $query = SupportTicket::with(['user', 'assignee', 'tenant'])
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        return $query->paginate($request->get('per_page', 20));
    }

    public function show(SupportTicket $ticket)
    {
        // Admin can see all, no tenant check
        $ticket->load(['user', 'assignee', 'messages.user', 'tenant']);
        return response()->json($ticket);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'message' => 'required|string',
        ]);

        $user = $request->user();

        $msg = SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $data['message'],
            'is_internal' => false,
        ]);

        $ticket->update([
            'last_reply_at' => now(),
            'status' => $ticket->status === 'open' ? 'in_progress' : $ticket->status,
        ]);

        $ticket->load('messages.user');

        // notify ticket owner
        $tenantAdmin = User::where('tenant_id', $ticket->tenant_id)->where('role', 'Administrator')->first();
        MailHelper::sendEmailNotification($tenantAdmin->email, 'Support Ticket Status Updated', 'You have received a new message on your support ticket: ' . $ticket->subject);

        return response()->json([
            'success' => true,
            'ticket' => $ticket,
            'message' => $msg,
        ]);
    }


    public function addInternalNote(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'message' => 'required|string',
        ]);

        $user = $request->user();

        $msg = SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $data['message'],
            'is_internal' => true,
        ]);

        return response()->json([
            'success' => true,
            'note' => $msg,
        ]);
    }

    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        // Admin only (enforced by middleware, but can double-check role if you like)
        $data = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        $ticket->update([
            'status' => $data['status'],
        ]);

        $tenantAdmin = User::where('tenant_id', $ticket->tenant_id)->where('role', 'Administrator')->first();
        MailHelper::sendEmailNotification($tenantAdmin->email, 'Support Ticket Status Updated', 'The status of your support ticket has been updated to ' . $data['status']);

        return response()->json([
            'success' => true,
            'ticket' => $ticket->fresh(),
        ]);
    }

    public function assign(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $ticket->update([
            'assigned_to' => $data['assigned_to'],
        ]);

        return response()->json([
            'success' => true,
            'ticket' => $ticket->fresh('assignee'),
        ]);
    }

    public function analyticsOverview(Request $request)
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();

        $base = SupportTicket::query();

        // Optional date range filter for admin
        if ($request->filled('start_date')) {
            $base->whereDate('created_at', '>=', $request->get('start_date'));
        }
        if ($request->filled('end_date')) {
            $base->whereDate('created_at', '<=', $request->get('end_date'));
        }

        $ticketsThisMonth = (clone $base)
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        $resolvedThisMonth = (clone $base)
            ->where('status', 'resolved')
            ->where('updated_at', '>=', $startOfMonth)
            ->count();

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // First response & resolution times using messages
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
            // resolution time: created_at -> updated_at when resolved/closed
            if (in_array($ticket->status, ['resolved', 'closed'])) {
                $resolutionSeconds[] = $ticket->created_at->diffInSeconds($ticket->updated_at);
            }

            // first response: first message from someone != creator
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

        return response()->json([
            'success' => true,
            'data' => [
                'tickets_this_month' => $ticketsThisMonth,
                'resolved_this_month' => $resolvedThisMonth,
                'by_status' => [
                    'open' => (int) ($byStatus['open'] ?? 0),
                    'in_progress' => (int) ($byStatus['in_progress'] ?? 0),
                    'resolved' => (int) ($byStatus['resolved'] ?? 0),
                    'closed' => (int) ($byStatus['closed'] ?? 0),
                ],
                'avg_first_response_m' => $avgFirstResponseMinutes,
                'avg_resolution_h' => $avgResolutionHours,
            ],
        ]);
    }

    public function ticketsPerDay(Request $request)
    {
        $days = (int) $request->get('days', 14);
        $today = Carbon::today();
        $start = $today->copy()->subDays($days - 1);

        $rows = SupportTicket::selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->whereDate('created_at', '>=', $start)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->date] = (int) $row->total;
        }

        $data = [];
        $cursor = $start->copy();
        while ($cursor <= $today) {
            $dateStr = $cursor->toDateString();
            $data[] = [
                'date' => $cursor->format('d M'),
                'count' => $map[$dateStr] ?? 0,
            ];
            $cursor->addDay();
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function ticketsByCategory(Request $request)
    {
        $rows = SupportTicket::select('category', DB::raw('COUNT(*) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                return [
                    'category' => $row->category ?? 'Uncategorized',
                    'total' => (int) $row->total,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function highFrictionTenants(Request $request)
    {
        $days = (int) $request->get('days', 30);
        $from = Carbon::now()->subDays($days);

        $rows = SupportTicket::select('tenant_id', DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $from)
            ->groupBy('tenant_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $tenantIds = $rows->pluck('tenant_id')->all();
        $tenants = Tenant::whereIn('id', $tenantIds)->get()->keyBy('id');

        $data = $rows->map(function ($row) use ($tenants) {
            $tenant = $tenants[$row->tenant_id] ?? null;

            return [
                'tenant_id' => $row->tenant_id,
                'tenant_name' => $tenant?->name ?? 'Unknown',
                'tickets' => (int) $row->total,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
