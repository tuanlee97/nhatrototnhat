<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';
// Lấy danh sách chi nhánh
// Lấy danh sách chi nhánh
function getBranches() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    error_log("User ID: $user_id, Role: $role", 0);

    // Phân trang
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Điều kiện lọc
    $conditions = ['b.deleted_at IS NULL', 'u.deleted_at IS NULL']; // Fixed typo here
    $params = [];

    // Phân quyền
    if ($role === 'customer') {
        // Khách hàng chỉ xem chi nhánh của hợp đồng active
        $conditions[] = "b.id IN (SELECT branch_id FROM contracts WHERE user_id = ? AND status = 'active' AND deleted_at IS NULL)";
        $params[] = $user_id;
    } elseif ($role === 'admin') {
        // Admin có thể xem tất cả chi nhánh, không cần branch_id
        if (!empty($_GET['branch_id'])) {
            // Nếu có branch_id, lọc theo chi nhánh cụ thể
            $branch_id = (int)$_GET['branch_id'];
            $conditions[] = "b.id = ?";
            $params[] = $branch_id;
        }
        // Không thêm điều kiện nếu không có branch_id
       
    } elseif ($role === 'owner') {
        $conditions[] = "b.owner_id = ?";
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $conditions[] = "b.id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND deleted_at IS NULL)";
        $params[] = $user_id;
    } else {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập danh sách chi nhánh'], 403);
        return;
    }

    // Tìm kiếm theo tên chi nhánh hoặc địa chỉ
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(b.name LIKE ? OR b.address LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    // Xây dựng truy vấn
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT b.id, b.owner_id, b.name, b.address, b.phone, b.created_at, u.name AS owner_name
        FROM branches b
        JOIN users u ON b.owner_id = u.id
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Đếm tổng số bản ghi
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM branches b JOIN users u ON b.owner_id = u.id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // Truy vấn dữ liệu
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Lấy danh sách dịch vụ cho mỗi chi nhánh
        foreach ($branches as &$branch) {
            $stmt = $pdo->prepare("
                SELECT id AS service_id, name, price, unit, type
                FROM services
                WHERE branch_id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$branch['id']]);
            $branch['services'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        responseJson([
            'status' => 'success',
            'data' => $branches,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách chi nhánh: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
function getBranchById() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $branch_id = getResourceIdFromUri('#/branches/([0-9]+)#');

    // Điều kiện phân quyền
    $condition = "";
    $params = [$branch_id];

    if ($role === 'admin') {
        // Admin thấy tất cả
    } elseif ($role === 'owner') {
        $condition = "AND b.owner_id = ?";
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $condition = "AND b.id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
        $params[] = $user_id;
    } else {
        responseJson(['message' => 'Không có quyền xem chi nhánh này'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT b.id, b.owner_id, b.name, b.address, b.phone, b.created_at, u.name AS owner_name
            FROM branches b
            JOIN users u ON b.owner_id = u.id
            WHERE b.id = ? $condition
        ");
        $stmt->execute($params);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$branch) {
            responseJson(['message' => 'Chi nhánh không tồn tại hoặc bạn không có quyền truy cập'], 404);
            return;
        }

        // Lấy danh sách dịch vụ của chi nhánh
        $stmt = $pdo->prepare("
            SELECT id AS service_id, name, price, unit
            FROM services
            WHERE branch_id = ?
        ");
        $stmt->execute([$branch_id]);
        $branch['services'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson(['data' => $branch, 'message' => 'Lấy chi nhánh thành công']);
    } catch (PDOException $e) {
        logError("Lỗi lấy chi nhánh ID $branch_id: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function createBranch() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['message' => 'Không có quyền tạo chi nhánh'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['name', 'address', 'owner_id']);

    $name = sanitizeInput($input['name']);
    $address = sanitizeInput($input['address']);
    $phone = isset($input['phone']) ? sanitizeInput($input['phone']) : null;
    $owner_id = (int)$input['owner_id'];

    // Kiểm tra định dạng số điện thoại
    if ($phone && !preg_match('/^0[0-9]{9}$/', $phone)) {
        responseJson(['message' => 'Số điện thoại không hợp lệ'], 400);
        return;
    }

    // Kiểm tra owner_id hợp lệ
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND role = 'owner'");
    $stmt->execute([$owner_id]);
    if (!$stmt->fetch()) {
        responseJson(['message' => 'Người dùng không tồn tại hoặc không phải chủ nhà trọ'], 400);
        return;
    }

    // Nếu là owner, chỉ được tạo chi nhánh cho chính mình
    if ($role === 'owner' && $owner_id !== $user_id) {
        responseJson(['message' => 'Bạn chỉ có thể tạo chi nhánh cho chính mình'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO branches (owner_id, name, address, phone)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$owner_id, $name, $address, $phone]);
        $branch_id = $pdo->lastInsertId();

        createNotification($pdo, $owner_id, "Chi nhánh '$name' đã được tạo thành công.");
        responseJson([
            'data' => ['id' => $branch_id],
            'message' => 'Tạo chi nhánh thành công'
        ], 201);
    } catch (PDOException $e) {
        logError("Lỗi tạo chi nhánh: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function updateBranch() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $branch_id = getResourceIdFromUri('#/branches/([0-9]+)#');

    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['message' => 'Không có quyền cập nhật chi nhánh'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['name', 'address', 'owner_id']);

    $name = sanitizeInput($input['name']);
    $address = sanitizeInput($input['address']);
    $phone = isset($input['phone']) ? sanitizeInput($input['phone']) : null;
    $owner_id = (int)$input['owner_id'];

    // Kiểm tra định dạng số điện thoại
    if ($phone && !preg_match('/^0[0-9]{9}$/', $phone)) {
        responseJson(['message' => 'Số điện thoại không hợp lệ'], 400);
        return;
    }

    // Kiểm tra owner_id hợp lệ
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND role = 'owner'");
    $stmt->execute([$owner_id]);
    if (!$stmt->fetch()) {
        responseJson(['message' => 'Người dùng không tồn tại hoặc không phải chủ nhà trọ'], 400);
        return;
    }

    // Kiểm tra quyền truy cập
    $condition = ($role === 'admin') ? "" : "AND owner_id = ?";
    $params = [$branch_id];
    if ($role === 'owner') {
        $params[] = $user_id;
    }

    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? $condition");
    $stmt->execute($params);
    if (!$stmt->fetch()) {
        responseJson(['message' => 'Chi nhánh không tồn tại hoặc bạn không có quyền cập nhật'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE branches 
            SET name = ?, address = ?, phone = ?, owner_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $address, $phone, $owner_id, $branch_id]);

        createNotification($pdo, $owner_id, "Chi nhánh ID $branch_id đã được cập nhật.");
        responseJson([
            'data' => ['id' => $branch_id],
            'message' => 'Cập nhật chi nhánh thành công'
        ]);
    } catch (PDOException $e) {
        logError("Lỗi cập nhật chi nhánh ID $branch_id: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function deleteBranch() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $branch_id = getResourceIdFromUri('#/branches/([0-9]+)#');

    // Kiểm tra quyền: Chỉ admin hoặc chủ chi nhánh (owner) của chi nhánh đó mới được xóa
    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['message' => 'Không có quyền xóa chi nhánh'], 403);
        return;
    }

    try {
        // Kiểm tra chi nhánh có tồn tại
        checkResourceExists($pdo, 'branches', $branch_id);

        // Nếu là owner, kiểm tra xem branch_id có thuộc về user_id không
        if ($role === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
            $stmt->execute([$branch_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['message' => 'Bạn không có quyền xóa chi nhánh này'], 403);
                return;
            }
        }

        // Thực hiện xóa chi nhánh
        $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ?");
        $stmt->execute([$branch_id]);

        createNotification($pdo, $user_id, "Chi nhánh ID $branch_id đã được xóa.");
        responseJson(['message' => 'Xóa chi nhánh thành công']);
    } catch (PDOException $e) {
        logError("Lỗi xóa chi nhánh ID $branch_id: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>