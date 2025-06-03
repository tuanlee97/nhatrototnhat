<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getNotifications() {
    $pdo = getDB();

    // Pagination
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Filter conditions
    $conditions = [];
    $params = [];

    if (!empty($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "n.user_id = ?";
        $params[] = $_GET['user_id'];
    }
    if (isset($_GET['is_read']) && in_array($_GET['is_read'], ['true', 'false'])) {
        $conditions[] = "n.is_read = ?";
        $params[] = $_GET['is_read'] === 'true' ? 1 : 0;
    }

    // Search
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "n.message LIKE ?";
        $params[] = $search;
    }

    // Build query
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT n.*, u.name AS user_name
        FROM notifications n
        JOIN users u ON n.user_id = u.id
        $whereClause
        ORDER BY n.created_at DESC
    ";

    // Count total records
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications n $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Query data with pagination
    $query .= " LIMIT $limit OFFSET $offset"; 
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $notifications,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createNotification($pdo, $user_id, $message) {
    $user = verifyJWT();
    $creator_id = $user['user_id'];
    $creator_role = $user['role'];

    // Sanitize input
    $user_id = filter_var($user_id, FILTER_VALIDATE_INT);
    $message = sanitizeInput($message);
    $is_read = false; // Default value for is_read

    if (!$user_id) {
        responseJson(['status' => 'error', 'message' => 'ID người dùng không hợp lệ'], 400);
        return;
    }

    try {
        // Check if target user exists
        checkResourceExists($pdo, 'users', $user_id);

        // Permission check
        if ($creator_role !== 'admin') {
            if ($creator_role === 'owner') {
                // Owner can notify employees/customers in their branches
                $stmt = $pdo->prepare("
                    SELECT 1 FROM users u
                    LEFT JOIN branch_customers bc ON u.id = bc.user_id
                    LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
                    JOIN branches b ON (bc.branch_id = b.id OR ea.branch_id = b.id)
                    WHERE u.id = ? AND b.owner_id = ? AND u.role IN ('employee', 'customer') AND u.deleted_at IS NULL
                ");
                $stmt->execute([$user_id, $creator_id]);
                if (!$stmt->fetch()) {
                    responseJson(['status' => 'error', 'message' => 'Bạn không có quyền gửi thông báo cho người dùng này'], 403);
                    return;
                }
            } elseif ($creator_role === 'employee') {
                // Employee can notify customers in their assigned branches or those they created
                $stmt = $pdo->prepare("
                    SELECT 1 FROM users u
                    JOIN branch_customers bc ON u.id = bc.user_id
                    JOIN employee_assignments ea ON bc.branch_id = ea.branch_id
                    WHERE u.id = ? AND ea.employee_id = ? AND u.role = 'customer' AND u.deleted_at IS NULL
                ");
                $stmt->execute([$user_id, $creator_id]);
                if (!$stmt->fetch()) {
                    $stmt = $pdo->prepare("
                        SELECT 1 FROM users u
                        JOIN branch_customers bc ON u.id = bc.user_id
                        WHERE u.id = ? AND bc.created_by = ? AND u.role = 'customer' AND u.deleted_at IS NULL
                    ");
                    $stmt->execute([$user_id, $creator_id]);
                    if (!$stmt->fetch()) {
                        responseJson(['status' => 'error', 'message' => 'Bạn không có quyền gửi thông báo cho người dùng này'], 403);
                        return;
                    }
                }
            } else {
                // Customers cannot create notifications for others
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền tạo thông báo'], 403);
                return;
            }
        }

        // Insert notification
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, message, is_read, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $message, $is_read]);

        $notification_id = $pdo->lastInsertId();
        responseJson(['status' => 'success', 'data' => ['notification_id' => $notification_id]]);
    } catch (Exception $e) {
        error_log("Lỗi tạo notification: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getNotificationById() {
    $notificationId = getResourceIdFromUri('#/notifications/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT id, user_id, message, is_read, created_at FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $user['user_id']]);
        $notification = $stmt->fetch();

        if (!$notification) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy thông báo'], 404);
        }
        responseJson(['status' => 'success', 'data' => $notification]);
    } catch (Exception $e) {
        logError('Lỗi lấy notification ID ' . $notificationId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateNotification() {
    $notificationId = getResourceIdFromUri('#/notifications/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['message', 'is_read']);
    $user = verifyJWT();

    $message = sanitizeInput($input['message']);
    $isRead = filter_var($input['is_read'], FILTER_VALIDATE_BOOLEAN);

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'notifications', $notificationId);
        $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $user['user_id']]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Thông báo không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("
            UPDATE notifications SET message = ?, is_read = ?
            WHERE id = ?
        ");
        $stmt->execute([$message, $isRead, $notificationId]);

        responseJson(['status' => 'success', 'message' => 'Cập nhật thông báo thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật notification ID ' . $notificationId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchNotification() {
    $notificationId = getResourceIdFromUri('#/notifications/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'notifications', $notificationId);
        $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $user['user_id']]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Thông báo không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['message'])) {
            $updates[] = "message = ?";
            $params[] = sanitizeInput($input['message']);
        }
        if (isset($input['is_read'])) {
            $isRead = filter_var($input['is_read'], FILTER_VALIDATE_BOOLEAN);
            $updates[] = "is_read = ?";
            $params[] = $isRead;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE notifications SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $notificationId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        responseJson(['status' => 'success', 'message' => 'Cập nhật thông báo thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch notification ID ' . $notificationId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteNotification() {
    $notificationId = getResourceIdFromUri('#/notifications/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'notifications', $notificationId);
        $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $user['user_id']]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Thông báo không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ?");
        $stmt->execute([$notificationId]);
        responseJson(['status' => 'success', 'message' => 'Xóa thông báo thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa notification ID ' . $notificationId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>