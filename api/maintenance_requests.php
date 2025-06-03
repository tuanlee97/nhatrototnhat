<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';

// Customer: Create Maintenance Request
function createMaintenanceRequest() {
    $pdo = getDB();
    $user = verifyJWT();
    $current_user_id = $user['user_id'];
    $role = $user['role'];

    // Ensure the user is a customer (middleware should already enforce this, but adding for safety)
    if ($role !== 'customer') {
        responseJson(['message' => 'Chỉ khách hàng mới có thể tạo yêu cầu bảo trì'], 403);
        return;
    }

    // Parse request body
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        responseJson(['message' => 'Dữ liệu yêu cầu không hợp lệ'], 400);
        return;
    }

    // Extract and validate required fields
    $description = isset($data['description']) ? trim($data['description']) : null;
    $room_id = isset($data['room_id']) ? (int)$data['room_id'] : null;
    $status = isset($data['status']) ? trim($data['status']) : 'pending';

    if (!$description || !$room_id) {
        responseJson(['message' => 'Thiếu mô tả hoặc ID phòng'], 400);
        return;
    }

    // Validate status
    $valid_statuses = ['pending', 'in_progress', 'completed'];
    if (!in_array($status, $valid_statuses)) {
        responseJson(['message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    // For customers, status should always be 'pending' when creating
    $status = 'pending';

    // Verify the customer is renting the specified room (via active contract)
    $stmt = $pdo->prepare("
        SELECT id FROM contracts 
        WHERE user_id = ? AND room_id = ? AND status = 'active' AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$current_user_id, $room_id]);
    $contract = $stmt->fetchColumn();

    if (!$contract) {
        responseJson(['message' => 'Bạn không có quyền tạo yêu cầu bảo trì cho phòng này'], 403);
        return;
    }

    // Insert maintenance request into the database
    $stmt = $pdo->prepare("
        INSERT INTO maintenance_requests (room_id, description, status, created_by, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$room_id, $description, $status, $current_user_id]);

    $request_id = $pdo->lastInsertId();

    // Fetch the created maintenance request to return
    $stmt = $pdo->prepare("
        SELECT mr.id, mr.room_id, mr.description, mr.status, mr.created_by, mr.created_at, r.name AS room_name
        FROM maintenance_requests mr
        JOIN rooms r ON mr.room_id = r.id
        WHERE mr.id = ?
    ");
    $stmt->execute([$request_id]);
    $maintenance_request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$maintenance_request) {
        responseJson(['message' => 'Không thể tìm thấy yêu cầu vừa tạo'], 500);
        return;
    }

    responseJson([
        'status' => 'success',
        'data' => $maintenance_request
    ], 201);
}

// Get Maintenance Requests for a Customer
function getCustomerMaintenanceRequests($userId) {
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

    $conditions = ["mr.created_by = ?"];
    $params = [$current_user_id];

    // Thêm điều kiện tìm kiếm nếu có search
    if (!empty($_GET['search'])) {
        $search = '%' . trim($_GET['search']) . '%';
        $conditions[] = "(mr.description LIKE ? OR r.name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    if (!empty($_GET['status'])) {
        $conditions[] = "mr.status = ?";
        $params[] = $_GET['status'];
    }

    $conditions[] = "mr.deleted_at IS NULL";
    $whereClause = "WHERE " . implode(" AND ", $conditions);

    $query = "
        SELECT 
            mr.id, mr.room_id, mr.description, mr.status, mr.created_by, mr.created_at,
            r.name AS room_name, b.name AS branch_name
        FROM maintenance_requests mr
        JOIN rooms r ON mr.room_id = r.id
        JOIN branches b ON r.branch_id = b.id
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests mr JOIN rooms r ON mr.room_id = r.id JOIN branches b ON r.branch_id = b.id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $requests,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Get All Maintenance Requests (Admin/Owner/Employee)
function getAllMaintenanceRequests() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    // Xác định branch
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

    // Tìm kiếm
    if (!empty($_GET['search'])) {
        $search = '%' . trim($_GET['search']) . '%';
        $conditions[] = "(mr.description LIKE ? OR r.name LIKE ? OR u.name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Trạng thái
    if (!empty($_GET['status'])) {
        $conditions[] = "mr.status = ?";
        $params[] = $_GET['status'];
    }

    // Không lấy bản ghi đã xóa
    $conditions[] = "mr.deleted_at IS NULL";
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    // JOIN đầy đủ cho tất cả truy vấn
    $baseJoin = "
        FROM maintenance_requests mr
        JOIN rooms r ON mr.room_id = r.id
        JOIN users u ON mr.created_by = u.id
        JOIN branches b ON r.branch_id = b.id
    ";

    // Truy vấn chính
    $query = "
        SELECT 
            mr.id, mr.room_id, mr.description, mr.status, mr.created_by, mr.created_at,
            r.name AS room_name, r.branch_id,
            u.name AS user_name, 
            b.name AS branch_name
        $baseJoin
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Truy vấn đếm
        $countQuery = "SELECT COUNT(*) $baseJoin $whereClause";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // Truy vấn dữ liệu
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Truy vấn thống kê
        $statsQuery = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) as completed
            $baseJoin
            $whereClause
        ";
        $statsStmt = $pdo->prepare($statsQuery);
        $statsStmt->execute($params);
        $statistics = $statsStmt->fetch(PDO::FETCH_ASSOC);

        // Phản hồi
        responseJson([
            'status' => 'success',
            'data' => [
                'requests' => $requests,
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
        error_log("Lỗi lấy danh sách yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Update Maintenance Request (Admin/Owner/Employee)
function updateMaintenanceRequest($id) {
    $pdo = getDB();
    $user = verifyJWT();
    $role = $user['role'];

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['status'])) {
        responseJson(['message' => 'Thiếu trạng thái để cập nhật'], 400);
        return;
    }

    $status = trim($data['status']);
    $valid_statuses = ['pending', 'in_progress', 'completed'];
    if (!in_array($status, $valid_statuses)) {
        responseJson(['message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    $stmt = $pdo->prepare("SELECT room_id FROM maintenance_requests WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        responseJson(['message' => 'Yêu cầu bảo trì không tồn tại'], 404);
        return;
    }

    $room_id = $request['room_id'];
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
            responseJson(['message' => 'Không có quyền cập nhật yêu cầu này'], 403);
            return;
        }
    } elseif ($role === 'employee') {
        $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND branch_id = ?");
        $stmt->execute([$user['user_id'], $branch_id]);
        if (!$stmt->fetchColumn()) {
            responseJson(['message' => 'Không có quyền cập nhật yêu cầu này'], 403);
            return;
        }
    }

    $stmt = $pdo->prepare("UPDATE maintenance_requests SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $id]);

    responseJson(['status' => 'success', 'message' => 'Cập nhật yêu cầu bảo trì thành công']);
}

// Delete Maintenance Request (Admin only)
function deleteMaintenanceRequest($id) {
    $pdo = getDB();
    $user = verifyJWT();
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['message' => 'Chỉ admin mới có thể xóa yêu cầu bảo trì'], 403);
        return;
    }

    $stmt = $pdo->prepare("UPDATE maintenance_requests SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        responseJson(['message' => 'Yêu cầu bảo trì không tồn tại'], 404);
        return;
    }

    responseJson(['status' => 'success', 'message' => 'Xóa yêu cầu bảo trì thành công']);
}