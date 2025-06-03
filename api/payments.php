<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getPayments() {
    $pdo = getDB();

    // Pagination
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Filter conditions
    $conditions = [];
    $params = [];

    if (!empty($_GET['status']) && in_array($_GET['status'], ['pending', 'completed', 'failed'])) {
        $conditions[] = "p.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['contract_id']) && filter_var($_GET['contract_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "p.contract_id = ?";
        $params[] = $_GET['contract_id'];
    }
    if (!empty($_GET['min_amount']) && filter_var($_GET['min_amount'], FILTER_VALIDATE_FLOAT)) {
        $conditions[] = "p.amount >= ?";
        $params[] = $_GET['min_amount'];
    }
    if (!empty($_GET['max_amount']) && filter_var($_GET['max_amount'], FILTER_VALIDATE_FLOAT)) {
        $conditions[] = "p.amount <= ?";
        $params[] = $_GET['max_amount'];
    }

    // Search
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "u.name LIKE ?";
        $params[] = $search;
    }

    // Build query
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT p.*, c.user_id, u.name AS customer_name, c.room_id, b.name AS branch_name
        FROM payments p
        JOIN contracts c ON p.contract_id = c.id
        JOIN users u ON c.user_id = u.id
        JOIN branches b ON c.branch_id = b.id
        $whereClause
    ";

    // Count total records
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p JOIN contracts c ON p.contract_id = c.id JOIN users u ON c.user_id = u.id $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Query data with pagination
    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $payments,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}


function createPayment() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['contract_id', 'amount', 'payment_date', 'payment_method']);
    $user = verifyJWT();

    $contractId = filter_var($input['contract_id'], FILTER_VALIDATE_INT);
    $amount = filter_var($input['amount'], FILTER_VALIDATE_FLOAT);
    $paymentDate = validateDate($input['payment_date']);
    $paymentMethod = in_array($input['payment_method'], ['cash', 'bank_transfer', 'online']) ? $input['payment_method'] : 'cash';
    $status = in_array($input['status'] ?? 'completed', ['pending', 'completed', 'failed']) ? $input['status'] : 'completed';

    if (!$contractId || !$amount || $amount <= 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'contracts', $contractId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE c.id = ? AND b.owner_id = ?");
            $stmt->execute([$contractId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id WHERE c.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$contractId, $user['user_id']]);
        } elseif ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM contracts WHERE id = ? AND user_id = ?");
            $stmt->execute([$contractId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo thanh toán'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Hợp đồng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("
            INSERT INTO payments (contract_id, amount, payment_date, payment_method, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$contractId, $amount, $paymentDate, $paymentMethod, $status]);

        $paymentId = $pdo->lastInsertId();
        createNotification($pdo, $user['user_id'], "Thanh toán ID $paymentId đã được ghi nhận cho hợp đồng ID $contractId.");
        responseJson(['status' => 'success', 'data' => ['payment_id' => $paymentId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo payment: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getPaymentById() {
    $paymentId = getResourceIdFromUri('#/payments/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        $query = "
            SELECT p.id, p.contract_id, p.amount, p.payment_date, p.payment_method, p.status, p.created_at,
                   c.user_id, u.username AS user_name, r.name AS room_name
            FROM payments p
            JOIN contracts c ON p.contract_id = c.id
            JOIN users u ON c.user_id = u.id
            JOIN rooms r ON c.room_id = r.id
            WHERE p.id = ?
        ";
        $params = [$paymentId];
        if ($user['role'] === 'customer') {
            $query .= " AND c.user_id = ?";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'employee') {
            $query .= " AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'owner') {
            $query .= " AND r.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
            $params[] = $user['user_id'];
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $payment = $stmt->fetch();

        if (!$payment) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy thanh toán'], 404);
        }
        responseJson(['status' => 'success', 'data' => $payment]);
    } catch (Exception $e) {
        logError('Lỗi lấy payment ID ' . $paymentId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updatePayment() {
    $paymentId = getResourceIdFromUri('#/payments/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['contract_id', 'amount', 'payment_date', 'payment_method', 'status']);
    $user = verifyJWT();

    $contractId = filter_var($input['contract_id'], FILTER_VALIDATE_INT);
    $amount = filter_var($input['amount'], FILTER_VALIDATE_FLOAT);
    $paymentDate = validateDate($input['payment_date']);
    $paymentMethod = in_array($input['payment_method'], ['cash', 'bank_transfer', 'online']) ? $input['payment_method'] : 'cash';
    $status = in_array($input['status'], ['pending', 'completed', 'failed']) ? $input['status'] : 'completed';

    if (!$contractId || !$amount || $amount <= 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'payments', $paymentId);
        checkResourceExists($pdo, 'contracts', $contractId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE c.id = ? AND b.owner_id = ?");
            $stmt->execute([$contractId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id WHERE c.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$contractId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa thanh toán'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Hợp đồng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("
            UPDATE payments SET contract_id = ?, amount = ?, payment_date = ?, payment_method = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$contractId, $amount, $paymentDate, $paymentMethod, $status, $paymentId]);

        createNotification($pdo, $user['user_id'], "Thanh toán ID $paymentId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật thanh toán thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật payment ID ' . $paymentId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchPayment() {
    $paymentId = getResourceIdFromUri('#/payments/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'payments', $paymentId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT p.id FROM payments p JOIN contracts c ON p.contract_id = c.id JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE p.id = ? AND b.owner_id = ?");
            $stmt->execute([$paymentId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT p.id FROM payments p JOIN contracts c ON p.contract_id = c.id JOIN rooms r ON c.room_id = r.id WHERE p.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$paymentId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa thanh toán'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Thanh toán không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['contract_id'])) {
            $contractId = filter_var($input['contract_id'], FILTER_VALIDATE_INT);
            checkResourceExists($pdo, 'contracts', $contractId);
            if ($user['role'] === 'owner') {
                $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE c.id = ? AND b.owner_id = ?");
                $stmt->execute([$contractId, $user['user_id']]);
            } elseif ($user['role'] === 'employee') {
                $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id WHERE c.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
                $stmt->execute([$contractId, $user['user_id']]);
            }
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Hợp đồng không hợp lệ hoặc bạn không có quyền'], 403);
            }
            $updates[] = "contract_id = ?";
            $params[] = $contractId;
        }
        if (isset($input['amount'])) {
            $amount = filter_var($input['amount'], FILTER_VALIDATE_FLOAT);
            if ($amount === false || $amount <= 0) {
                responseJson(['status' => 'error', 'message' => 'Số tiền không hợp lệ'], 400);
            }
            $updates[] = "amount = ?";
            $params[] = $amount;
        }
        if (!empty($input['payment_date'])) {
            $paymentDate = validateDate($input['payment_date']);
            $updates[] = "payment_date = ?";
            $params[] = $paymentDate;
        }
        if (!empty($input['payment_method'])) {
            $paymentMethod = in_array($input['payment_method'], ['cash', 'bank_transfer', 'online']) ? $input['payment_method'] : 'cash';
            $updates[] = "payment_method = ?";
            $params[] = $paymentMethod;
        }
        if (!empty($input['status'])) {
            $status = in_array($input['status'], ['pending', 'completed', 'failed']) ? $input['status'] : 'completed';
            $updates[] = "status = ?";
            $params[] = $status;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE payments SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $paymentId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Thanh toán ID $paymentId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật thanh toán thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch payment ID ' . $paymentId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deletePayment() {
    $paymentId = getResourceIdFromUri('#/payments/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'payments', $paymentId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT p.id FROM payments p JOIN contracts c ON p.contract_id = c.id JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE p.id = ? AND b.owner_id = ?");
            $stmt->execute([$paymentId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT p.id FROM payments p JOIN contracts c ON p.contract_id = c.id JOIN rooms r ON c.room_id = r.id WHERE p.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$paymentId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa thanh toán'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Thanh toán không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        responseJson(['status' => 'success', 'message' => 'Xóa thanh toán thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa payment ID ' . $paymentId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

// Hàm hỗ trợ validate ngày
function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày không hợp lệ (Y-m-d)'], 400);
    }
    return $date;
}
?>