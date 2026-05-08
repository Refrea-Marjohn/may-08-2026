<?php
require_once 'config.php';

// Notification Helper Class
class NotificationHelper {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    // Create notification for specific user
    public function createForUser($user_id, $title, $message, $type = 'info') {
        $stmt = $this->conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        $stmt->bind_param("isss", $user_id, $title, $message, $type);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }
    
    // Create notification for all users of a specific role
    public function createForRole($role, $title, $message, $type = 'info') {
        $stmt = $this->conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) SELECT id, ?, ?, ?, 0, NOW() FROM users WHERE role = ?");
        $stmt->bind_param("ssss", $title, $message, $type, $role);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }
    
    // Create notification for all users
    public function createForAll($title, $message, $type = 'info') {
        $stmt = $this->conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) SELECT id, ?, ?, ?, 0, NOW() FROM users");
        $stmt->bind_param("sss", $title, $message, $type);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }
    
    // Loan application notifications
    public function loanApplicationSubmitted($borrower_id, $loan_amount) {
        // Notify borrower
        $this->createForUser(
            $borrower_id,
            'Loan Application Submitted',
            "Your loan application for ₱" . number_format($loan_amount, 2) . " has been submitted successfully.",
            'success'
        );
        
        // Notify all admins and accountants
        $this->createForRole(
            'admin',
            'New Loan Application',
            "A new loan application for ₱" . number_format($loan_amount, 2) . " has been submitted.",
            'info'
        );
        
        $this->createForRole(
            'accountant',
            'New Loan Application',
            "A new loan application for ₱" . number_format($loan_amount, 2) . " requires review.",
            'info'
        );
    }
    
    // Loan status notifications
    public function loanStatusUpdated($borrower_id, $status, $admin_comment = '') {
        $title = 'Loan Application ' . ucfirst($status);
        $message = "Your loan application has been {$status}.";
        
        if ($status === 'rejected' && $admin_comment) {
            $message .= " Reason: " . $admin_comment;
        } elseif ($status === 'approved') {
            $message .= " Please check your loan details.";
        }
        
        $type = $status === 'approved' ? 'success' : ($status === 'rejected' ? 'warning' : 'info');
        
        $this->createForUser($borrower_id, $title, $message, $type);
    }
    
    // Payment notifications
    public function paymentReceived($borrower_id, $amount, $payment_type) {
        $this->createForUser(
            $borrower_id,
            'Payment Received',
            "Your {$payment_type} payment of ₱" . number_format($amount, 2) . " has been received.",
            'success'
        );
    }
    
    // Payment reminder notifications
    public function paymentReminder($borrower_id, $due_amount, $due_date) {
        $this->createForUser(
            $borrower_id,
            'Payment Reminder',
            "Your payment of ₱" . number_format($due_amount, 2) . " is due on " . date('M d, Y', strtotime($due_date)) . ".",
            'warning'
        );
    }
    
    // System notifications
    public function systemMaintenance($title, $message) {
        $this->createForAll(
            $title,
            $message,
            'info'
        );
    }
    
    // User management notifications
    public function userAccountUpdated($user_id, $updated_by, $changes) {
        $this->createForUser(
            $user_id,
            'Account Updated',
            "Your account has been updated by {$updated_by}. Changes: {$changes}",
            'info'
        );
    }
    
    // New user registration notification for admins
    public function newUserRegistered($user_name, $role) {
        $this->createForRole(
            'admin',
            'New User Registration',
            "A new {$role} account has been created for {$user_name}.",
            'info'
        );
    }
    
    // Document upload notifications
    public function documentUploaded($user_id, $document_type) {
        $this->createForUser(
            $user_id,
            'Document Uploaded',
            "Your {$document_type} has been successfully uploaded.",
            'success'
        );
    }
    
    // Get user notifications
    public function getUserNotifications($user_id, $limit = 10) {
        $stmt = $this->conn->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = [];
        
        while ($row = $result->fetch_assoc()) {
            $notifications[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'message' => $row['message'],
                'type' => $row['type'],
                'is_read' => (bool)$row['is_read'],
                'created_at' => $row['created_at'],
                'time_ago' => $this->timeAgo($row['created_at'])
            ];
        }
        
        $stmt->close();
        return $notifications;
    }
    
    // Get unread count
    public function getUnreadCount($user_id) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($result['count'] ?? 0);
    }
    
    private function timeAgo($datetime) {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff/60) . ' min ago';
        if ($diff < 86400) return floor($diff/3600) . ' hours ago';
        if ($diff < 604800) return floor($diff/86400) . ' days ago';
        return date('M d, Y', $time);
    }
}

// Usage examples:
/*
$notification_helper = new NotificationHelper($conn);

// Loan application submitted
$notification_helper->loanApplicationSubmitted($borrower_id, 50000);

// Loan approved
$notification_helper->loanStatusUpdated($borrower_id, 'approved');

// Loan rejected with comment
$notification_helper->loanStatusUpdated($borrower_id, 'rejected', 'Incomplete documentation');

// Payment reminder
$notification_helper->paymentReminder($borrower_id, 5000, '2024-01-15');

// System maintenance
$notification_helper->systemMaintenance('Scheduled Maintenance', 'The system will be down for maintenance on Jan 15, 2024 from 2AM to 4AM.');

// New user registered
$notification_helper->newUserRegistered('John Doe', 'borrower');
*/
?>
