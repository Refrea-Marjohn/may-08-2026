<?php
require_once 'config.php';

// Enhanced Notification System
class NotificationSystem {
    private $conn;
    private $user_id;
    
    public function __construct($conn, $user_id) {
        $this->conn = $conn;
        $this->user_id = $user_id;
    }
    
    public function getUnreadCount() {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($result['count'] ?? 0);
    }
    
    public function getNotifications($limit = 5) {
        $stmt = $this->conn->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bind_param("ii", $this->user_id, $limit);
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
    
    public function markAsRead($notification_id = null) {
        if ($notification_id) {
            // Mark single notification
            $stmt = $this->conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id = ?");
            $stmt->bind_param("ii", $this->user_id, $notification_id);
            $success = $stmt->execute();
            $stmt->close();
            return $success;
        } else {
            // Mark all as read
            $stmt = $this->conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->bind_param("i", $this->user_id);
            $success = $stmt->execute();
            $stmt->close();
            return $success;
        }
    }

    public function deleteNotification($notification_id) {
        $stmt = $this->conn->prepare("DELETE FROM notifications WHERE user_id = ? AND id = ?");
        $stmt->bind_param("ii", $this->user_id, $notification_id);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }
    
    public function createNotification($title, $message, $type = 'info') {
        $stmt = $this->conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
        $stmt->bind_param("issss", $this->user_id, $title, $message, $type);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
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

// Handle AJAX requests only when this file is requested directly (not when included by another page)
$is_direct_request = isset($_SERVER['SCRIPT_FILENAME']) && (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME']));
if ($is_direct_request && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in']);
        exit;
    }
    
    $notification_system = new NotificationSystem($conn, $_SESSION['user_id']);
    
    switch ($_POST['action']) {
        case 'mark_one_read':
            $notification_id = (int)$_POST['notification_id'];
            $success = $notification_system->markAsRead($notification_id);
            echo json_encode(['success' => $success]);
            break;
            
        case 'mark_all_read':
            $success = $notification_system->markAsRead();
            echo json_encode(['success' => $success]);
            break;
            
        case 'get_notifications':
            $notifications = $notification_system->getNotifications();
            $unread_count = $notification_system->getUnreadCount();
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unread_count
            ]);
            break;
            
        case 'delete_notification':
            $notification_id = (int)($_POST['notification_id'] ?? 0);
            $success = $notification_id > 0 ? $notification_system->deleteNotification($notification_id) : false;
            echo json_encode(['success' => $success]);
            break;

        case 'create_test':
            $title = 'Test Notification - ' . date('h:i A');
            $message = 'This is a test notification to verify system works.';
            $type = 'info';
            $success = $notification_system->createNotification($title, $message, $type);
            echo json_encode(['success' => $success]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit;
}

// Initialize notification system for display
if (isset($_SESSION['user_id'])) {
    $notification_system = new NotificationSystem($conn, $_SESSION['user_id']);
    $notifications = $notification_system->getNotifications();
    $unread_count = $notification_system->getUnreadCount();
} else {
    $notifications = [];
    $unread_count = 0;
}
?>
<button class="icon-button notification-button" title="Notifications" type="button" aria-label="Notifications">
    <i class="fas fa-bell"></i>
    <?php if ($unread_count > 0): ?>
        <span class="notification-badge"><?php echo $unread_count; ?></span>
    <?php endif; ?>
</button>
<div class="notification-panel" id="notificationPanel">
    <div class="notification-header">
        <div class="notification-title-row">
            <i class="fas fa-bell"></i> Notifications
        </div>
        <div class="notification-actions">
            <?php if ($unread_count > 0): ?>
                <button type="button" class="notification-mark-btn" data-action="mark_all_read">
                    <i class="fas fa-check-double"></i> Mark all as read
                </button>
            <?php endif; ?>
        </div>
    </div>
    <?php if (empty($notifications)): ?>
        <div class="notification-empty">No notifications yet.</div>
    <?php else: ?>
        <?php foreach ($notifications as $item): ?>
            <div class="notification-item <?php echo !empty($item['is_read']) ? 'is-read' : 'is-unread'; ?>" data-notification-id="<?php echo (int) ($item['id'] ?? 0); ?>" data-is-read="<?php echo !empty($item['is_read']) ? '1' : '0'; ?>">
                <div class="notification-item-top">
                    <div class="notification-title"><?php echo htmlspecialchars($item['title'] ?? ''); ?></div>
                    <div class="notification-item-actions">
                        <?php if (empty($item['is_read'])): ?>
                            <button type="button" class="notification-item-mark" data-mark-read title="Mark as read" aria-label="Mark as read">
                                <i class="fas fa-check"></i>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="notification-item-delete" title="Delete" aria-label="Delete notification">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="notification-message"><?php echo htmlspecialchars($item['message'] ?? ''); ?></div>
                <div class="notification-time"><?php echo htmlspecialchars($item['time_ago'] ?? ''); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<div id="notificationDeleteModal" class="notification-delete-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="notificationDeleteModalTitle">
    <div class="notification-delete-modal__backdrop" data-notification-modal-dismiss tabindex="-1"></div>
    <div class="notification-delete-modal__dialog">
        <div class="notification-delete-modal__icon" aria-hidden="true">
            <i class="fas fa-trash-alt"></i>
        </div>
        <h2 id="notificationDeleteModalTitle" class="notification-delete-modal__title">Delete notification?</h2>
        <p class="notification-delete-modal__text">This will remove the notification from your list. You cannot undo this action.</p>
        <div class="notification-delete-modal__actions">
            <button type="button" class="notification-delete-modal__btn notification-delete-modal__btn--secondary" data-notification-modal-dismiss>Cancel</button>
            <button type="button" class="notification-delete-modal__btn notification-delete-modal__btn--danger" data-notification-modal-confirm>Delete</button>
        </div>
    </div>
</div>
