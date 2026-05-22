<?php
// app/Http/Controllers/API/SupportTicketController.php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\TicketReply;
use App\Models\TicketAttachment;
use App\Models\TicketActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SupportTicketController extends BaseController
{


    /**
     * Get all tickets with filters
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = SupportTicket::with(['user', 'assignedTo', 'company', 'branch']);

        // Role-based filtering
        if ($this->hasPermission(1)) {
            // Root can see all tickets
            Log::info('User is root');
        } elseif ($this->hasPermission(21)) {
            $query->where('company_id', $this->getCompanyId());
            Log::info('User is company');
        } elseif ($this->hasPermission(20)) {
            $query->whereIn('branch_id', $this->getUserBranches());
            Log::info('User is branch');
        } elseif ($this->hasPermission(19)) {
            Log::info('User is officer');
            $query->where(function ($q) use ($user) {
                $q->where('assigned_to', $this->getUserId())
                    ->orWhereNull('assigned_to');
            });
        } else {
            // Regular users see only their own tickets
            $query->where('user_id', $this->getUserId());
        }

        // Apply filters
        if ($request->has('status') && $request->status) {
            $query->whereIn('status', explode(',', $request->status));
        }
        if ($request->has('priority') && $request->priority) {
            $query->where('priority', $request->priority);
        }
        if ($request->has('category') && $request->category) {
            $query->where('category', $request->category);
        }
        if ($request->has('assigned_to') && $request->assigned_to) {
            if ($request->assigned_to == 'me') {
                $assigned_to = $this->getUserId();
                Log::info('Assigned to: ' . $assigned_to);
                $query->where('assigned_to', $assigned_to);
            } else {
                Log::info('Assigned to: UNASSIGNED');
                $query->whereNull('assigned_to');
            }
        }
        if ($request->has('company_id') && $request->company_id && $user->hasRole('root')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->has('branch_id') && $request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('ticket_number', 'like', "%{$request->search}%")
                    ->orWhere('title', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        // Date range filter
        if ($request->has('from_date') && $request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date') && $request->to_date) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $tickets = $query->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        // Add statistics
        $stats = [
            'total' => $query->count(),
            'open' => SupportTicket::whereIn('status', ['open', 'in_progress', 'pending'])->count(),
            'resolved' => SupportTicket::where('status', 'resolved')->count(),
            'closed' => SupportTicket::where('status', 'closed')->count(),
            'avg_response_time' => SupportTicket::avg('response_time_hours'),
            'avg_resolution_time' => SupportTicket::avg('resolution_time_hours'),
        ];

        return response()->json([
            'success' => true,
            'data' => $tickets,
            'stats' => $stats,
            'filters' => $request->all()
        ]);
    }

    /**
     * Create new ticket
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:255',
            'category' => 'required|in:technical,billing,feature_request,bug,general,other',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'attachments' => 'sometimes|array',
            'attachments.*' => 'image|mimes:jpeg,png,jpg,gif|max:5120' // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $userBranches = $this->getUserBranches();
        //Log::info('User branches: ' . json_encode($userBranches));
        $userBranch = !empty($userBranches) ? $userBranches[0] : null;

        DB::beginTransaction();
        try {
            $ticket = SupportTicket::create([
                'ticket_number' => SupportTicket::generateTicketNumber(),
                'company_id' => $this->getCompanyId(),
                'branch_id' => $userBranch,
                'user_id' => $this->getUserId(),
                'subject' => $request->subject,
                'category' => $request->category,
                'title' => $request->title,
                'description' => $request->description,
                'priority' => $request->priority ?? 'medium',
                'status' => 'open',
                'opened_at' => now(),
            ]);

            // Handle attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store("tickets/{$ticket->id}", 'public');
                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => $user->id,
                        'filename' => $file->hashName(),
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'path' => $path,
                    ]);
                }
            }

            // Log activity
            $this->logActivity($ticket, $user->id, 'created', 'Ticket created', null, [
                'title' => $ticket->title,
                'category' => $ticket->category,
                'priority' => $ticket->priority
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ticket created successfully',
                'data' => $ticket->load(['user', 'attachments'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create ticket: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single ticket details
     */
    public function show($id)
    {
        $user = request()->user();
        $ticket = SupportTicket::with(['user', 'assignedTo', 'company', 'branch', 'replies.user', 'attachments', 'activityLogs.user'])
            ->findOrFail($id);

        // Check authorization
        if (
            !$this->hasPermission(1) &&
            //!$this->hasPermission(21) &&
            $ticket->user_id !== $this->getUserId() &&
            $this->getCompanyId() !== $ticket->company_id
        ) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $ticket
        ]);
    }

    /**
     * Update ticket
     */
    public function update(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);
        $user = $request->user();

        // Check authorization
        if (!$user->hasRole('support_agent') && !$user->hasRole('root')) {
            return response()->json(['success' => false, 'message' => 'Only support agents can update tickets'], 403);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'sometimes|string|max:255',
            'category' => 'sometimes|in:technical,billing,feature_request,bug,general,other',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'admin_notes' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $oldValues = $ticket->toArray();
        $ticket->update($request->only(['subject', 'category', 'title', 'description', 'admin_notes']));

        // Log changes
        $changes = array_diff_assoc($ticket->toArray(), $oldValues);
        if (!empty($changes)) {
            $this->logActivity($ticket, $user->id, 'updated', 'Ticket details updated', $oldValues, $ticket->toArray());
        }

        return response()->json([
            'success' => true,
            'message' => 'Ticket updated successfully',
            'data' => $ticket
        ]);
    }

    /**
     * Assign ticket to support agent
     */
    public function assign(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);
        $user = $request->user();

        $assignedTo = $request->assigned_to;
        if ($assignedTo === 'me') {
            $assignedTo = $this->getUserId();
        }

        $validator = Validator::make(['assigned_to' => $assignedTo], [
            'assigned_to' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $oldAssigned = $ticket->assigned_to;
        $ticket->assigned_to = $assignedTo;

        if ($ticket->status === 'open') {
            $ticket->status = 'in_progress';
        }

        $ticket->save();

        $this->logActivity(
            $ticket,
            $user->id,
            'assigned',
            "Ticket assigned to user ID: {$request->assigned_to}",
            ['assigned_to' => $oldAssigned],
            ['assigned_to' => $request->assigned_to]
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket assigned successfully',
            'data' => $ticket->load('assignedTo')
        ]);
    }

    /**
     * Change ticket status
     */
    public function changeStatus(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:open,in_progress,pending,resolved,closed'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $oldStatus = $ticket->status;
        $ticket->status = $request->status;

        // Handle timestamps
        if ($request->status === 'resolved' && !$ticket->resolved_at) {
            $ticket->resolved_at = now();
            if ($ticket->opened_at) {
                $ticket->resolution_time_hours = $ticket->opened_at->diffInHours($ticket->resolved_at);
            }
        }

        if (in_array($request->status, ['closed', 'resolved']) && !$ticket->closed_at) {
            $ticket->closed_at = now();
        }

        if ($request->status === 'reopened') {
            $ticket->closed_at = null;
            $ticket->resolved_at = null;
        }

        $ticket->save();

        $this->logActivity(
            $ticket,
            $user->id,
            'status_changed',
            "Status changed from {$oldStatus} to {$request->status}",
            ['status' => $oldStatus],
            ['status' => $request->status]
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket status updated',
            'data' => $ticket
        ]);
    }

    /**
     * Change ticket priority
     */
    public function changePriority(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'priority' => 'required|in:low,medium,high,urgent'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $oldPriority = $ticket->priority;
        $ticket->priority = $request->priority;
        $ticket->save();

        $this->logActivity(
            $ticket,
            $user->id,
            'priority_changed',
            "Priority changed from {$oldPriority} to {$request->priority}",
            ['priority' => $oldPriority],
            ['priority' => $request->priority]
        );

        return response()->json([
            'success' => true,
            'message' => 'Ticket priority updated',
            'data' => $ticket
        ]);
    }

    /**
     * Add reply to ticket
     */
    public function addReply(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'message' => 'required|string',
            'is_internal_note' => 'sometimes|boolean',
            'attachment' => 'sometimes|image|mimes:jpeg,png,jpg,gif|max:5120'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            //$isAdminReply = $this->hasPermission(1) || $user->hasRole('is_support');
            $isAdminReply = $this->hasPermission(1);
            $isInternalNote = $request->is_internal_note ?? false;

            $reply = TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $request->message,
                'is_admin_reply' => $isAdminReply,
                'is_internal_note' => $isInternalNote,
            ]);

            // Handle attachment
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $path = $file->store("tickets/{$ticket->id}/replies", 'public');

                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $user->id,
                    'filename' => $file->hashName(),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'path' => $path,
                ]);

                $reply->attachment_path = $path;
                $reply->save();
            }

            // Update ticket status if needed
            if (!$isAdminReply && $ticket->status === 'resolved') {
                $ticket->status = 'open';
                $ticket->save();
            } elseif ($isAdminReply && $ticket->status === 'open') {
                $ticket->status = 'in_progress';
                $ticket->save();
            }

            // Record first response time if this is the first admin reply
            if ($isAdminReply && !$isInternalNote && !$ticket->first_response_at) {
                $ticket->first_response_at = now();
                if ($ticket->opened_at) {
                    $ticket->response_time_hours = $ticket->opened_at->diffInHours($ticket->first_response_at);
                }
                $ticket->save();
            }

            $this->logActivity(
                $ticket,
                $user->id,
                'replied',
                $isInternalNote ? 'Internal note added' : 'Reply added to ticket',
                null,
                ['message_preview' => substr($request->message, 0, 100)]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Reply added successfully',
                'data' => $reply->load('user')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add reply: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get ticket statistics for dashboard
     */
    public function getStats(Request $request)
    {
        $user = $request->user();
        $query = SupportTicket::query();

        // Apply role-based filtering
        if (!$this->hasPermission(1)) {
            if ($this->hasPermission(21)) {
                $query->where('company_id', $user->company_id);
            } elseif ($this->hasPermission(20)) {
                $query->where('branch_id', $user->branch_id);
            } elseif (!$this->hasPermission(19)) {
                $query->where('user_id', $user->id);
            }
        }

        $stats = [
            'total' => $query->count(),
            'open' => (clone $query)->whereIn('status', ['open', 'in_progress'])->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'resolved' => (clone $query)->where('status', 'resolved')->count(),
            'closed' => (clone $query)->where('status', 'closed')->count(),
            'by_priority' => [
                'urgent' => (clone $query)->where('priority', 'urgent')->count(),
                'high' => (clone $query)->where('priority', 'high')->count(),
                'medium' => (clone $query)->where('priority', 'medium')->count(),
                'low' => (clone $query)->where('priority', 'low')->count(),
            ],
            'by_category' => [
                'technical' => (clone $query)->where('category', 'technical')->count(),
                'billing' => (clone $query)->where('category', 'billing')->count(),
                'feature_request' => (clone $query)->where('category', 'feature_request')->count(),
                'bug' => (clone $query)->where('category', 'bug')->count(),
                'general' => (clone $query)->where('category', 'general')->count(),
            ],
            'avg_response_time' => (clone $query)->avg('response_time_hours'),
            'avg_resolution_time' => (clone $query)->avg('resolution_time_hours'),
            'tickets_this_week' => (clone $query)->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'resolved_this_week' => (clone $query)->whereBetween('resolved_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Log ticket activity
     */
    private function logActivity($ticket, $userId, $action, $description, $oldValues = null, $newValues = null)
    {
        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
