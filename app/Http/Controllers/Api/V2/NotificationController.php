<?php
// app/Http/Controllers/Api/NotificationController.php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\NotificationApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    protected NotificationApiService $notificationService;
    
    public function __construct(NotificationApiService $notificationService)
    {
        $this->notificationService = $notificationService;
       
    }
    
    /**
     * Get user notifications (for polling)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $lastId = $request->input('last_id', 0);
        $limit = $request->input('limit', 50);
        
        $notifications = $this->notificationService->getUserNotifications(
            $user->id, 
            $lastId, 
            $limit
        );
        
        $unreadCount = $this->notificationService->getUnreadCount($user->id);
        
        return response()->json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => $unreadCount,
            'last_id' => $notifications->isNotEmpty() ? $notifications->first()->id : $lastId,
        ]);
    }
    
    /**
     * Get notification by ID
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        $notification = \App\Models\Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();
            
        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => $notification
        ]);
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        $result = $this->notificationService->markAsRead($user->id, $id);
        
        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Notification not found'
        ], 404);
    }
    
    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $count = $this->notificationService->markAllAsRead($user->id);
        
        return response()->json([
            'success' => true,
            'message' => "{$count} notifications marked as read",
            'count' => $count
        ]);
    }
    
    /**
     * Get unread notifications count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $count = $this->notificationService->getUnreadCount($user->id);
        
        return response()->json([
            'success' => true,
            'unread_count' => $count
        ]);
    }
    
    /**
     * Delete notification
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        $result = $this->notificationService->deleteNotification($user->id, $id);
        
        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'Notification deleted'
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Notification not found'
        ], 404);
    }
    
    /**
     * Delete all notifications
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $count = $this->notificationService->deleteAllNotifications($user->id);
        
        return response()->json([
            'success' => true,
            'message' => "{$count} notifications deleted",
            'count' => $count
        ]);
    }
}