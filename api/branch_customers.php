<?php
require_once __DIR__ . '/utils/common.php';

function getBranchCustomers() {
    $pdo = getDB();
    $user = verifyJWT();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    // Role-based access control
    if ($user['role'] === 'owner') {
        // Owners only see customers from their branches
        $conditions[] = "b.owner_id = ?";
        $params[] = $user['user_id'];
    }
    // Admins can filter by owner_id if provided
    if ($user['role'] === 'admin' && !empty($_GET['owner_id']) && filter_var($_GET['owner_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "b.owner_id = ?";
        $params[] = $_GET['owner_id'];
    }
    // Additional filters
    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "bc.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }
    if (!empty($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "bc.user_id = ?";
        $params[] = $_GET['user_id'];
    }

    // Search by customer name
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "u.name LIKE ?";
        $params[] = $search;
    }

    // Build query
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT bc.*, u.name AS customer_name, b.name AS branch_name
        FROM branch_customers bc
        LEFT JOIN users u ON bc.user_id = u.id
        LEFT JOIN branches b ON bc.branch_id = b.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM branch_customers bc $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $customers = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $customers,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createBranchCustomer() {
    $pdo = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['user_id', 'branch_id']);

    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);

    if (!$userId || !$branchId) {
        responseJson(['status' => 'error', 'message' => 'ID không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'users', $userId);
    checkResourceExists($pdo, 'branches', $branchId);

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user['role'] !== 'customer') {
        responseJson(['status' => 'error', 'message' => 'Người dùng không phải khách hàng'], 400);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO branch_customers (user_id, branch_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$userId, $branchId]);
        createNotification($pdo, $userId, "Bạn đã được thêm vào chi nhánh ID $branchId");
        responseJson(['status' => 'success', 'message' => 'Thêm khách hàng chi nhánh thành công']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            responseJson(['status' => 'error', 'message' => 'Khách hàng đã được thêm vào chi nhánh này'], 409);
        }
        throw $e;
    }
}

function getBranchCustomerById() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/branch_customers/([0-9]+)$#');
    checkResourceExists($pdo, 'branch_customers', $id);

    $stmt = $pdo->prepare("
        SELECT bc.*, u.username, u.name, u.email, b.name AS branch_name
        FROM branch_customers bc
        JOIN users u ON bc.user_id = u.id
        JOIN branches b ON bc.branch_id = b.id
        WHERE bc.id = ?
    ");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();
    responseJson(['status' => 'success', 'data' => $customer]);
}

function updateBranchCustomer() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/branch_customers/([0-9]+)$#');
    checkResourceExists($pdo, 'branch_customers', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['user_id', 'branch_id']);

    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);

    if (!$userId || !$branchId) {
        responseJson(['status' => 'error', 'message' => 'ID không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'users', $userId);
    checkResourceExists($pdo, 'branches', $branchId);

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user['role'] !== 'customer') {
        responseJson(['status' => 'error', 'message' => 'Người dùng không phải khách hàng'], 400);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE branch_customers
            SET user_id = ?, branch_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$userId, $branchId, $id]);
        createNotification($pdo, $userId, "Thông tin khách hàng của bạn tại chi nhánh ID $branchId đã được cập nhật");
        responseJson(['status' => 'success', 'message' => 'Cập nhật khách hàng chi nhánh thành công']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            responseJson(['status' => 'error', 'message' => 'Khách hàng đã được thêm vào chi nhánh này'], 409);
        }
        throw $e;
    }
}

function patchBranchCustomer() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/branch_customers/([0-9]+)$#');
    checkResourceExists($pdo, 'branch_customers', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    $allowedFields = ['user_id', 'branch_id'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[] = filter_var($input[$field], FILTER_VALIDATE_INT);
        }
    }

    if (empty($updates)) {
        responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
    }

    if (isset($input['user_id'])) {
        checkResourceExists($pdo, 'users', $input['user_id']);
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$input['user_id']]);
        $user = $stmt->fetch();
        if ($user['role'] !== 'customer') {
            responseJson(['status' => 'error', 'message' => 'Người dùng không phải khách hàng'], 400);
        }
    }

    if (isset($input['branch_id'])) {
        checkResourceExists($pdo, 'branches', $input['branch_id']);
    }

    $params[] = $id;
    $query = "UPDATE branch_customers SET " . implode(', ', $updates) . " WHERE id = ?";
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        if ($stmt->rowCount() > 0) {
            $userId = $input['user_id'] ?? $pdo->query("SELECT user_id FROM branch_customers WHERE id = $id")->fetchColumn();
            createNotification($pdo, $userId, "Thông tin khách hàng của bạn tại chi nhánh đã được cập nhật");
            responseJson(['status' => 'success', 'message' => 'Cập nhật khách hàng chi nhánh thành công']);
        } else {
            responseJson(['status' => 'success', 'message' => 'Không có thay đổi']);
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            responseJson(['status' => 'error', 'message' => 'Khách hàng đã được thêm vào chi nhánh này'], 409);
        }
        throw $e;
    }
}

function deleteBranchCustomer() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/branch_customers/([0-9]+)$#');
    checkResourceExists($pdo, 'branch_customers', $id);

    $stmt = $pdo->prepare("SELECT user_id, branch_id FROM branch_customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();

    $stmt = $pdo->prepare("DELETE FROM branch_customers WHERE id = ?");
    $stmt->execute([$id]);

    createNotification($pdo, $customer['user_id'], "Bạn đã bị xóa khỏi chi nhánh ID {$customer['branch_id']}");
    responseJson(['status' => 'success', 'message' => 'Xóa khách hàng chi nhánh thành công']);
}
?>