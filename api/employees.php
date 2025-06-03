<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getEmployees() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "ea.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }

    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(u.name LIKE ? OR u.email LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE u.role = 'employee' AND " . implode(" AND ", $conditions) : "WHERE u.role = 'employee'";
    $query = "
        SELECT u.id, u.username, u.name, u.email, u.created_at, ea.branch_id, b.name AS branch_name
        FROM users u
        LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
        LEFT JOIN branches b ON ea.branch_id = b.id
        $whereClause
        GROUP BY u.id
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN employee_assignments ea ON u.id = ea.employee_id $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $employees,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createEmployee() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['username', 'email', 'password']);
    $userData = sanitizeUserInput($input);
    $userData['email'] = validateEmail($userData['email']);
    $password = password_hash($input['password'], PASSWORD_DEFAULT);

    $pdo = getDB();
    try {
        checkUserExists($pdo, $userData['email'], $userData['username']);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, name, email, password, phone, role, status, provider)
            VALUES (?, ?, ?, ?, ?, 'employee', 'active', 'email')
        ");
        $stmt->execute([$userData['username'], $userData['name'], $userData['email'], $password, $userData['phone']]);

        $userId = $pdo->lastInsertId();
        $jwt = generateJWT($userId, 'employee');
        $token = $jwt['token'];
        $userData['exp'] = $jwt['exp'] ?? null;
        createNotification($pdo, $userId, "Chào mừng {$userData['username']} đã được thêm làm nhân viên!");
        responseJson(['status' => 'success', 'data' => ['token' => $token, 'user' => $userData]]);
    } catch (Exception $e) {
        logError('Lỗi tạo nhân viên: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function updateEmployee() {
    $userId = getResourceIdFromUri('#/employees/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['username', 'email']);

    $userData = sanitizeUserInput($input);
    $userData['email'] = validateEmail($userData['email']);
    $password = !empty($input['password']) ? password_hash($input['password'], PASSWORD_DEFAULT) : null;

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'users', $userId);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee'");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy nhân viên'], 404);
        }

        checkUserExists($pdo, $userData['email'], $userData['username'], $userId);

        $query = "UPDATE users SET username = ?, name = ?, email = ?, phone = ?";
        $params = [$userData['username'], $userData['name'], $userData['email'], $userData['phone']];
        if ($password) {
            $query .= ", password = ?";
            $params[] = $password;
        }
        $query .= " WHERE id = ? AND role = 'employee'";
        $params[] = $userId;

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $userId, "Thông tin nhân viên {$userData['username']} đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật nhân viên thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật nhân viên ID ' . $userId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchEmployee() {
    $userId = getResourceIdFromUri('#/employees/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'users', $userId);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee'");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy nhân viên'], 404);
        }

        $updates = [];
        $params = [];
        if (!empty($input['username'])) {
            $username = sanitizeInput($input['username']);
            checkUserExists($pdo, null, $username, $userId);
            $updates[] = "username = ?";
            $params[] = $username;
        }
        if (!empty($input['email'])) {
            $email = validateEmail($input['email']);
            checkUserExists($pdo, $email, null, $userId);
            $updates[] = "email = ?";
            $params[] = $email;
        }
        if (!empty($input['password'])) {
            $updates[] = "password = ?";
            $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
        }
        if (!empty($input['name'])) {
            $updates[] = "name = ?";
            $params[] = sanitizeInput($input['name']);
        }
        if (isset($input['phone'])) {
            $updates[] = "phone = ?";
            $params[] = sanitizeInput($input['phone']);
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ? AND role = 'employee'";
        $params[] = $userId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $userId, "Thông tin nhân viên đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật nhân viên thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch nhân viên ID ' . $userId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteEmployee() {
    $userId = getResourceIdFromUri('#/employees/([0-9]+)#');
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'users', $userId);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee'");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy nhân viên'], 404);
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'employee'");
        $stmt->execute([$userId]);
        responseJson(['status' => 'success', 'message' => 'Xóa nhân viên thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa nhân viên ID ' . $userId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>