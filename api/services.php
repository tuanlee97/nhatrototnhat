<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getServices() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Phân trang
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Điều kiện lọc
    $conditions = ['s.is_deleted = 0'];
    $params = [];

    // Tìm kiếm
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(s.name LIKE ?)";
        $params[] = $search;
    }

    // Branch ID
    if (!empty($_GET['branch_id']) && is_numeric($_GET['branch_id'])) {
        $branch_id = (int)$_GET['branch_id'];
        $conditions[] = "s.branch_id = ?";
        $params[] = $branch_id;
    }

    // Phân quyền dựa trên vai trò
    $branchCondition = '';
    if ($role === 'owner') {
        $branchCondition = "AND s.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $branchCondition = "AND s.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
        $params[] = $user_id;
    } elseif ($role === 'customer') {
        $branchCondition = "AND s.branch_id IN (SELECT branch_id FROM branch_customers WHERE user_id = ?)";
        $params[] = $user_id;
    }

    // Xây dựng truy vấn
    $whereClause = (!empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "") . $branchCondition;
    $query = "
        SELECT s.id, s.branch_id, s.name, s.price, s.unit, s.type, s.created_at
        FROM services s
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM services s $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
        return;
    }

    responseJson([
        'data' => $services,
        'message' => 'Lấy danh sách dịch vụ thành công',
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function getServiceById() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $service_id = getResourceIdFromUri('#/services/([0-9]+)#');

    $condition = "AND s.is_deleted = 0";
    $params = [$service_id];

    if ($role === 'owner') {
        $condition .= " AND s.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $condition .= " AND s.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
        $params[] = $user_id;
    } elseif ($role === 'customer') {
        $condition .= " AND s.branch_id IN (SELECT branch_id FROM branch_customers WHERE user_id = ?)";
        $params[] = $user_id;
    }

    try {
        $stmt = $pdo->prepare("SELECT s.id, s.branch_id, s.name, s.price, s.unit, s.type, s.created_at FROM services s WHERE s.id = ? $condition");
        $stmt->execute($params);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            responseJson(['message' => 'Dịch vụ không tồn tại hoặc bạn không có quyền truy cập'], 404);
            return;
        }

        responseJson(['data' => $service, 'message' => 'Lấy dịch vụ thành công']);
    } catch (PDOException $e) {
        error_log("Error fetching service ID $service_id: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function createService() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'owner') {
        responseJson(['message' => 'Không có quyền tạo dịch vụ'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['name', 'price', 'unit', 'branch_id']);

    $name = sanitizeInput($input['name']);
    $price = filter_var($input['price'], FILTER_VALIDATE_FLOAT);
    $unit = sanitizeInput($input['unit']);
    $branch_id = (int)$input['branch_id'];
    $type = isset($input['type']) && in_array($input['type'], ['electricity', 'water', 'other']) ? $input['type'] : 'other';

    if ($price === false || $price < 0) {
        responseJson(['message' => 'Giá dịch vụ không hợp lệ'], 400);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ?");
    $stmt->execute([$branch_id]);
    if (!$stmt->fetch()) {
        responseJson(['message' => 'Chi nhánh không tồn tại'], 400);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
    $stmt->execute([$branch_id, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['message' => 'Không có quyền tạo dịch vụ cho chi nhánh này'], 403);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM services WHERE branch_id = ? AND name = ? AND is_deleted = 0");
    $stmt->execute([$branch_id, $name]);
    if ($stmt->fetch()) {
        responseJson(['message' => 'Dịch vụ đã tồn tại trong chi nhánh này'], 400);
        return;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO services (branch_id, name, price, unit, type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$branch_id, $name, $price, $unit, $type]);

        $service_id = $pdo->lastInsertId();
        createNotification($pdo, $user_id, "Dịch vụ '$name' đã được thêm vào chi nhánh.");
        responseJson(['data' => ['id' => $service_id], 'message' => 'Tạo dịch vụ thành công'], 201);
    } catch (PDOException $e) {
        error_log("Error creating service: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function updateService() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $service_id = getResourceIdFromUri('#/services/([0-9]+)#');

    if ($role !== 'owner') {
        responseJson(['message' => 'Không có quyền cập nhật dịch vụ'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        responseJson(['message' => 'Không có dữ liệu được cung cấp'], 400);
        return;
    }

    $stmt = $pdo->prepare("SELECT s.id, s.branch_id FROM services s JOIN branches b ON s.branch_id = b.id WHERE s.id = ? AND b.owner_id = ? AND s.is_deleted = 0");
    $stmt->execute([$service_id, $user_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$service) {
        responseJson(['message' => 'Dịch vụ không tồn tại hoặc bạn không có quyền cập nhật'], 403);
        return;
    }

    $updates = [];
    $params = [];

    if (!empty($input['name'])) {
        $stmt = $pdo->prepare("SELECT id FROM services WHERE branch_id = ? AND name = ? AND id != ? AND is_deleted = 0");
        $stmt->execute([$service['branch_id'], sanitizeInput($input['name']), $service_id]);
        if ($stmt->fetch()) {
            responseJson(['message' => 'Tên dịch vụ đã tồn tại trong chi nhánh này'], 400);
            return;
        }
        $updates[] = "name = ?";
        $params[] = sanitizeInput($input['name']);
    }
    if (isset($input['price'])) {
        $price = filter_var($input['price'], FILTER_VALIDATE_FLOAT);
        if ($price === false || $price < 0) {
            responseJson(['message' => 'Giá dịch vụ không hợp lệ'], 400);
            return;
        }
        $updates[] = "price = ?";
        $params[] = $price;
    }
    if (!empty($input['unit'])) {
        $updates[] = "unit = ?";
        $params[] = sanitizeInput($input['unit']);
    }
    if (isset($input['type']) && in_array($input['type'], ['electricity', 'water', 'other'])) {
        $updates[] = "type = ?";
        $params[] = $input['type'];
    }
    if (!empty($input['branch_id'])) {
        $branch_id = (int)$input['branch_id'];
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ?");
        $stmt->execute([$branch_id]);
        if (!$stmt->fetch()) {
            responseJson(['message' => 'Chi nhánh không tồn tại'], 400);
            return;
        }
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
        $stmt->execute([$branch_id, $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['message' => 'Không có quyền chuyển dịch vụ sang chi nhánh này'], 403);
            return;
        }
        $updates[] = "branch_id = ?";
        $params[] = $branch_id;
    }

    if (empty($updates)) {
        responseJson(['message' => 'Không có trường nào để cập nhật'], 400);
        return;
    }

    try {
        $query = "UPDATE services SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $service_id;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user_id, "Dịch vụ ID $service_id đã được cập nhật.");
        responseJson(['data' => ['id' => $service_id], 'message' => 'Cập nhật dịch vụ thành công']);
    } catch (PDOException $e) {
        error_log("Error updating service ID $service_id: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function deleteService() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $service_id = getResourceIdFromUri('#/services/([0-9]+)#');

    if ($role !== 'owner') {
        responseJson(['message' => 'Không có quyền xóa dịch vụ'], 403);
        return;
    }

    $stmt = $pdo->prepare("SELECT s.id FROM services s JOIN branches b ON s.branch_id = b.id WHERE s.id = ? AND b.owner_id = ? AND s.is_deleted = 0");
    $stmt->execute([$service_id, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['message' => 'Dịch vụ không tồn tại hoặc bạn không có quyền xóa'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE services SET is_deleted = 1 WHERE id = ?");
        $stmt->execute([$service_id]);

        createNotification($pdo, $user_id, "Dịch vụ ID $service_id đã được xóa mềm.");
        responseJson(['data' => ['id' => $service_id], 'message' => 'Xóa dịch vụ thành công']);
    } catch (PDOException $e) {
        error_log("Error deleting service ID $service_id: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>