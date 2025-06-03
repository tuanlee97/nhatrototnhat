<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';

// Customer: Create Ticket
function createTicket() {
    $pdo = getDB();
    $user = verifyJWT();
    $current_user_id = $user['user_id'];
    $role = $user['role'];

    // Ensure the user is a customer
    if ($role !== 'customer') {
        responseJson(['message' => 'Chỉ khách hàng mới có thể tạo ticket'], 403);
        return;
    }

    // Parse request body
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        responseJson(['message' => 'Dữ liệu yêu cầu không hợp lệ'], 400);
        return;
    }

    // Extract and validate required fields
    $subject = isset($data['subject']) ? trim($data['subject']) : null;
    $description = isset($data['description']) ? trim($data['description']) : null;
    $room_id = isset($data['room_id']) ? (int)$data['room_id'] : null;
    $priority = isset($data['priority']) ? trim($data['priority']) : 'medium';
    $status = 'open'; // Default status for new tickets

    if (!$subject || !$description) {
        responseJson(['message' => 'Thiếu tiêu đề hoặc mô tả'], 400);
        return;
    }

    // Validate priority
    $valid_priorities = ['low', 'medium', 'high'];
    if (!in_array($priority, $valid_priorities)) {
        responseJson(['message' => 'Mức độ ưu tiên không hợp lệ'], 400);
        return;
    }

    // Validate room_id if provided
    $contract_id = null;
    if ($room_id) {
        $stmt = $pdo->prepare("
            SELECT id FROM contracts 
            WHERE user_id = ? AND room_id = ? AND status = 'active' AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$current_user_id, $room_id]);
        $contract_id = $stmt->fetchColumn();

        if (!$contract_id) {
            responseJson(['message' => 'Bạn không có quyền tạo ticket cho phòng này'], 403);
            return;
        }
    }

    // Insert ticket into the database
    $stmt = $pdo->prepare("
        INSERT INTO tickets (user_id, room_id, contract_id, subject, description, priority, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$current_user_id, $room_id, $contract_id, $subject, $description, $priority, $status]);

    $ticket_id = $pdo->lastInsertId();

    // Fetch the created ticket to return
    $stmt = $pdo->prepare("
        SELECT t.id, t.user_id, t.room_id, t.contract_id, t.subject, t.description, t.priority, t.status, t.created_at, 
               r.name AS room_name, u.name AS user_name
        FROM tickets t
        LEFT JOIN rooms r ON t.room_id = r.id
        JOIN users u ON t.user_id = u.id
        WHERE t.id = ?
    ");
    $stmt->execute([$ticket_id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        responseJson(['message' => 'Không thể tìm thấy ticket vừa tạo'], 500);
        return;
    }

    responseJson([
        'status' => 'success',
        'data' => $ticket
    ], 201);
}

// Get Tickets for a Customer
function getCustomerTickets($userId) {
    $pdo = getDB();
    $user = verifyJWT();
    $current_user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'customer' || $current_user_id != $userId) {
        responseJson(['message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = ["t.user_id = ?"];
    $params = [$current_user_id];

    // Add search condition
    if (!empty($_GET['search'])) {
        $search = '%' . trim($_GET['search']) . '%';
        $conditions[] = "(t.subject LIKE ? OR t.description LIKE ? OR r.name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Add status filter
    if (!empty($_GET['status'])) {
        $conditions[] = "t.status = ?";
        $params[] = $_GET['status'];
    }

    // Add priority filter
    if (!empty($_GET['priority'])) {
        $conditions[] = "t.priority = ?";
        $params[] = $_GET['priority'];
    }

    $conditions[] = "t.deleted_at IS NULL";
    $whereClause = "WHERE " . implode(" AND ", $conditions);

    $query = "
        SELECT 
            t.id, t.user_id, t.room_id, t.contract_id, t.subject, t.description, t.priority, t.status, t.created_at,
            r.name AS room_name, u.name AS user_name
        FROM tickets t
        LEFT JOIN rooms r ON t.room_id = r.id
        JOIN users u ON t.user_id = u.id
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t LEFT JOIN rooms r ON t.room_id = r.id JOIN users u ON t.user_id = u.id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch statistics
        $statsQuery = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as closed
            FROM tickets t
            LEFT JOIN rooms r ON t.room_id = r.id
            JOIN users u ON t.user_id = u.id
            $whereClause
        ";
        $statsStmt = $pdo->prepare($statsQuery);
        $statsStmt->execute($params);
        $statistics = $statsStmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'tickets' => $tickets,
                'statistics' => $statistics
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Get All Tickets (Admin/Owner/Employee)
function getAllTickets() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    // Filter by branch
    if (!empty($_GET['branch_id'])) {
        $branch_id = (int)$_GET['branch_id'];
        $conditions[] = "r.branch_id = ?";
        $params[] = $branch_id;
    } elseif ($role === 'owner') {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$user_id]);
        $branch_id = $stmt->fetchColumn();
        if ($branch_id) {
            $conditions[] = "r.branch_id = ?";
            $params[] = $branch_id;
        }
    } elseif ($role === 'employee') {
        $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $branch_id = $stmt->fetchColumn();
        if ($branch_id) {
            $conditions[] = "r.branch_id = ?";
            $params[] = $branch_id;
        }
    }

    // Add search condition
    if (!empty($_GET['search'])) {
        $search = '%' . trim($_GET['search']) . '%';
        $conditions[] = "(t.subject LIKE ? OR t.description LIKE ? OR r.name LIKE ? OR u.name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Add status filter
    if (!empty($_GET['status'])) {
        $conditions[] = "t.status = ?";
        $params[] = $_GET['status'];
    }

    // Add priority filter
    if (!empty($_GET['priority'])) {
        $conditions[] = "t.priority = ?";
        $params[] = $_GET['priority'];
    }

    $conditions[] = "t.deleted_at IS NULL";
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    $baseJoin = "
        FROM tickets t
        LEFT JOIN rooms r ON t.room_id = r.id
        JOIN users u ON t.user_id = u.id
        LEFT JOIN branches b ON r.branch_id = b.id
    ";

    $query = "
        SELECT 
            t.id, t.user_id, t.room_id, t.contract_id, t.subject, t.description, t.priority, t.status, t.created_at,
            r.name AS room_name, u.name AS user_name, b.name AS branch_name
        $baseJoin
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countQuery = "SELECT COUNT(*) $baseJoin $whereClause";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $statsQuery = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as closed
            $baseJoin
            $whereClause
        ";
        $statsStmt = $pdo->prepare($statsQuery);
        $statsStmt->execute($params);
        $statistics = $statsStmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'tickets' => $tickets,
                'statistics' => $statistics
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Update Ticket (Admin/Owner/Employee)
function updateTicket($id) {
    $pdo = getDB();
    $user = verifyJWT();
    $role = $user['role'];

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['status']) && !isset($data['priority'])) {
        responseJson(['message' => 'Thiếu trạng thái hoặc mức độ ưu tiên để cập nhật'], 400);
        return;
    }

    $status = isset($data['status']) ? trim($data['status']) : null;
    $priority = isset($data['priority']) ? trim($data['priority']) : null;

    $valid_statuses = ['open', 'in_progress', 'resolved', 'closed'];
    $valid_priorities = ['low', 'medium', 'high'];

    if ($status && !in_array($status, $valid_statuses)) {
        responseJson(['message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    if ($priority && !in_array($priority, $valid_priorities)) {
        responseJson(['message' => 'Mức độ ưu tiên không hợp lệ'], 400);
        return;
    }

    $stmt = $pdo->prepare("SELECT room_id FROM tickets WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        responseJson(['message' => 'Ticket không tồn tại'], 404);
        return;
    }

    $room_id = $ticket['room_id'];
    if ($room_id) {
        $stmt = $pdo->prepare("SELECT branch_id FROM rooms WHERE id = ?");
        $stmt->execute([$room_id]);
        $branch_id = $stmt->fetchColumn();

        if (!$branch_id) {
            responseJson(['message' => 'Phòng không tồn tại'], 404);
            return;
        }

        if ($role === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
            $stmt->execute([$branch_id, $user['user_id']]);
            if (!$stmt->fetchColumn()) {
                responseJson(['message' => 'Không có quyền cập nhật ticket này'], 403);
                return;
            }
        } elseif ($role === 'employee') {
            $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND branch_id = ?");
            $stmt->execute([$user['user_id'], $branch_id]);
            if (!$stmt->fetchColumn()) {
                responseJson(['message' => 'Không có quyền cập nhật ticket này'], 403);
                return;
            }
        }
    }

    $updateFields = [];
    $params = [];

    if ($status) {
        $updateFields[] = "status = ?";
        $params[] = $status;
        if ($status === 'resolved' || $status === 'closed') {
            $updateFields[] = "resolved_at = NOW()";
        }
    }

    if ($priority) {
        $updateFields[] = "priority = ?";
        $params[] = $priority;
    }

    $updateFields[] = "updated_at = NOW()";
    $params[] = $id;

    $query = "UPDATE tickets SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    responseJson(['status' => 'success', 'message' => 'Cập nhật ticket thành công']);
}

// Delete Ticket (Admin only)
function deleteTicket($id) {
    $pdo = getDB();
    $user = verifyJWT();
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['message' => 'Chỉ admin mới có thể xóa ticket'], 403);
        return;
    }

    $stmt = $pdo->prepare("UPDATE tickets SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        responseJson(['message' => 'Ticket không tồn tại'], 404);
        return;
    }

    responseJson(['status' => 'success', 'message' => 'Xóa ticket thành công']);
}