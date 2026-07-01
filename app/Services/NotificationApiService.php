<?php

namespace App\Services;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationApiService
{
    /**
     * Create a notification for a user
     */
    public function createNotification(
        int $userId, 
        string $title, 
        string $body, 
        array $data = [], 
        string $type = 'general'
    ): Notification {
        return Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'type' => $type,
            'is_read' => false,
            'created_at' => now(),
        ]);
    }
    
    /**
     * Create notification for multiple users (broadcast)
     */
    public function createBroadcastNotification(
        array $userIds, 
        string $title, 
        string $body, 
        array $data = [], 
        string $type = 'broadcast'
    ): void {
        $notifications = [];
        $now = now();
        
        foreach ($userIds as $userId) {
            $notifications[] = [
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'data' => json_encode($data),
                'type' => $type,
                'is_read' => false,
                'created_at' => $now,
            ];
        }
        
        // Batch insert for better performance
        DB::table('notifications')->insert($notifications);
    }
    
    /**
     * Get user's notifications with pagination
     */
    public function getUserNotifications(int $userId, int $lastId = 0, int $limit = 50)
    {
        $query = Notification::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');
        
        if ($lastId > 0) {
            $query->where('id', '>', $lastId);
        }
        
        return $query->limit($limit)->get();
    }
    
    /**
     * Get unread notifications count
     */
    public function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead(int $userId, int $notificationId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first();
            
        if ($notification) {
            $notification->markAsRead();
            return true;
        }
        
        return false;
    }
    
    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }
    
    /**
     * Delete notification
     */
    public function deleteNotification(int $userId, int $notificationId): bool
    {
        return Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->delete() > 0;
    }
    
    /**
     * Delete all notifications for a user
     */
    public function deleteAllNotifications(int $userId): int
    {
        return Notification::where('user_id', $userId)->delete();
    }
    
    /**
     * Send notification for loan application submission
     */
    public function notifyLoanApplication(int $adminId, array $loanData): void
    {
        $this->createNotification(
            $adminId,
            'New Loan Application',
            "A new loan application of {$loanData['amount']} has been submitted by {$loanData['customer_name']}",
            [
                'type' => 'loan_application',
                'loan_id' => $loanData['loan_id'],
                'amount' => $loanData['amount'],
                'customer_name' => $loanData['customer_name'],
            ],
            'loan_application'
        );
    }
    
    /**
     * Send notification for loan approval
     */
    public function notifyLoanApproval(int $customerId, array $loanData): void
    {
        $this->createNotification(
            $customerId,
            '🎉 Loan Approved!',
            "Congratulations! Your loan application of {$loanData['amount']} has been approved.",
            [
                'type' => 'loan_approved',
                'loan_id' => $loanData['loan_id'],
                'amount' => $loanData['amount'],
            ],
            'loan_approved'
        );
    }
    
    /**
     * Send notification for loan rejection
     */
    public function notifyLoanRejection(int $customerId, array $loanData): void
    {
        $this->createNotification(
            $customerId,
            'Loan Application Update',
            "We regret to inform you that your loan application of {$loanData['amount']} has been rejected. Reason: {$loanData['reason']}",
            [
                'type' => 'loan_rejected',
                'loan_id' => $loanData['loan_id'],
                'reason' => $loanData['reason'],
            ],
            'loan_rejected'
        );
    }
    
    /**
     * Send notification for loan disbursement
     */
    public function notifyLoanDisbursement(int $customerId, array $loanData): void
    {
        $this->createNotification(
            $customerId,
            '💰 Loan Disbursed',
            "Your loan of {$loanData['amount']} has been disbursed to your account.",
            [
                'type' => 'loan_disbursed',
                'loan_id' => $loanData['loan_id'],
                'amount' => $loanData['amount'],
            ],
            'loan_disbursed'
        );
    }
    
    /**
     * Send notification for payment due reminder
     */
    public function notifyPaymentDue(int $customerId, array $paymentData): void
    {
        $this->createNotification(
            $customerId,
            '⏰ Payment Reminder',
            "Your loan payment of {$paymentData['amount']} is due on {$paymentData['due_date']}",
            [
                'type' => 'payment_due',
                'loan_id' => $paymentData['loan_id'],
                'amount' => $paymentData['amount'],
                'due_date' => $paymentData['due_date'],
            ],
            'payment_due'
        );
    }
    
    /**
     * Send notification for payment received
     */
    public function notifyPaymentReceived(int $customerId, array $paymentData): void
    {
        $this->createNotification(
            $customerId,
            '✅ Payment Received',
            "Your payment of {$paymentData['amount']} has been received successfully.",
            [
                'type' => 'payment_received',
                'loan_id' => $paymentData['loan_id'],
                'amount' => $paymentData['amount'],
            ],
            'payment_received'
        );
    }
}