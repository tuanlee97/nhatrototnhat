<?php
require_once __DIR__ . '/utils/common.php';

function getEmployeeAssignments() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "ea.employee_id = ?";
        $params[] = $_GET['user_id'];
    }
    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "ea.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }

    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "u.name LIKE ?";
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT ea.*, u.name AS employee_name, b.name AS branch_name
        FROM employee_assignments ea
        LEFT JOIN users u ON ea.employee_id = u.id
        LEFT JOIN branches b ON ea.branch_id = b.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM employee_assignments ea $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $assignments = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $assignments,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}


function createEmployeeAssignment() {
    $pdo = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['employee_id', 'branch_id']);

    $employeeId = filter_var($input['employee_id'], FILTER_VALIDATE_INT);
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);

    if (!$employeeId || !$branchId) {
        responseJson(['status' => 'error', 'message' => 'ID không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'users', $employeeId);
    checkResourceExists($pdo, 'branches', $branchId);

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$employeeId]);
    $user = $stmt->fetch();
    if ($user['role'] !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Người dùng không phải nhân viên'], 400);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO employee_assignments (employee_id, branch_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$employeeId, $branchId]);
        createNotification($pdo, $employeeId, "Bạn đã được phân công đến chi nhánh ID $branchId");
        responseJson(['status' => 'success', 'message' => 'Phân công nhân viên thành công']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            responseJson(['status' => 'error', 'message' => 'Phân công đã tồn tại'], 409);
        }
        throw $e;
    }
}

function getEmployeeAssignmentById() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/employee_assignments/([0-9]+)$#');
    checkResourceExists($pdo, 'employee_assignments', $id);

    $stmt = $pdo->prepare("
        SELECT ea.*, u.username, u.name, b.name AS branch_name
        FROM employee_assignments ea
        JOIN users u ON ea.employee_id = u.id
        JOIN branches b ON ea.branch_id = b.id
        WHERE ea.id = ?
    ");
    $stmt->execute([$id]);
    $assignment = $stmt->fetch();
    responseJson(['status' => 'success', 'data' => $assignment]);
}

function updateEmployeeAssignment() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/employee_assignments/([0-9]+)$#');
    checkResourceExists($pdo, 'employee_assignments', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['employee_id', 'branch_id']);

    $employeeId = filter_var($input['employee_id'], FILTER_VALIDATE_INT);
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);

    if (!$employeeId || !$branchId) {
        responseJson(['status' => 'error', 'message' => 'ID không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'users', $employeeId);
    checkResourceExists($pdo, 'branches', $branchId);

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$employeeId]);
    $user = $stmt->fetch();
    if ($user['role'] !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Người dùng không phải nhân viên'], 400);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE employee_assignments
            SET employee_id = ?, branch_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$employeeId, $branchId, $id]);
        createNotification($pdo, $employeeId, "Phân công của bạn tại chi nhánh ID $branchId đã được cập nhật");
        responseJson(['status' => 'success', 'message' => 'Cập nhật phân công thành công']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            responseJson(['status' => 'error', 'message' => 'Phân công đã tồn tại'], 409);
        }
        throw $e;
    }
}

function patchEmployeeAssignment() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/employee_assignments/([0-9]+)$#');
    checkResourceExists($pdo, 'employee_assignments', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    $allowedFields = ['employee_id', 'branch_id'];
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

    if (isset($input['employee_id'])) {
        checkResourceExists($pdo, 'users', $input['employee_id']);
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$input['employee_id']]);
        $user = $stmt->fetch();
        if ($user['role'] !== 'employee') {
            responseJson(['status' => 'error', 'message' => 'Người dùng không phải nhân viên'], 400);
        }
    }

    if (isset($input['branch_id'])) {
        checkResourceExists($pdo, 'branches', $input['branch_id']);
    }

    $params[] = $id;
    $query = "UPDATE employee_assignments SET " . implode(', ', $updates) . " WHERE id = ?";
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        if ($stmt->rowCount() > 0) {
            $employeeId = $input['employee_id'] ?? $pdo->query("SELECT employee_id FROM employee_assignments WHERE id = $id")->fetchColumn();
            createNotification($pdo, $employeeId, "Phân công của bạn đã được cập nhật");
            responseJson(['status' => 'success', 'message' => 'Cập nhật phân công thành công']);
        } else {
            responseJson(['status' => 'success', 'message' => 'Không có thay đổi']);
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            responseJson(['status' => 'error', 'message' => 'Phân công đã tồn tại'], 409);
        }
        throw $e;
    }
}

function deleteEmployeeAssignment() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/employee_assignments/([0-9]+)$#');
    checkResourceExists($pdo, 'employee_assignments', $id);

    $stmt = $pdo->prepare("SELECT employee_id, branch_id FROM employee_assignments WHERE id = ?");
    $stmt->execute([$id]);
    $assignment = $stmt->fetch();

    $stmt = $pdo->prepare("DELETE FROM employee_assignments WHERE id = ?");
    $stmt->execute([$id]);

    createNotification($pdo, $assignment['employee_id'], "Phân công của bạn tại chi nhánh ID {$assignment['branch_id']} đã bị xóa");
    responseJson(['status' => 'success', 'message' => 'Xóa phân công thành công']);
}
?>