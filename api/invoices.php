<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Lấy danh sách hóa đơn
function getInvoices() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee', 'customer'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = ['i.deleted_at IS NULL'];
    $params = [];

    if ($role === 'customer') {
        // Khách hàng chỉ xem hóa đơn của hợp đồng của họ
        $conditions[] = "i.contract_id IN (SELECT id FROM contracts WHERE user_id = ?)";
        $params[] = $user_id;
    } elseif ($role === 'admin' && !empty($_GET['branch_id'])) {
        // Admin có thể lọc theo branch_id
        $branch_id = (int)$_GET['branch_id'];
        $conditions[] = "i.branch_id = ?";
        $params[] = $branch_id;
    } elseif ($role === 'owner') {
        $conditions[] = "i.branch_id IN (SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL)";
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $conditions[] = "i.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND deleted_at IS NULL)";
        $params[] = $user_id;
    }

    if (!empty($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
        $conditions[] = "DATE_FORMAT(i.due_date, '%Y-%m') = ?";
        $params[] = $_GET['month'];
    }

    if (!empty($_GET['status'])) {
        $conditions[] = "i.status = ?";
        $params[] = $_GET['status'];
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT 
            i.id, 
            i.contract_id, 
            i.branch_id, 
            i.amount, 
            i.due_date, 
            i.status, 
            i.created_at,
            c.room_id, 
            r.name AS room_name, 
            b.name AS branch_name, 
            p.payment_date,
            u.phone AS owner_phone, 
            u.qr_code_url, 
            u.bank_details
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.id
        JOIN rooms r ON c.room_id = r.id
        JOIN branches b ON i.branch_id = b.id
        LEFT JOIN payments p ON i.contract_id = p.contract_id AND i.due_date = p.due_date AND p.deleted_at IS NULL
        JOIN users u ON b.owner_id = u.id
        $whereClause
        ORDER BY i.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            $whereClause
        ");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($invoices as &$invoice) {
            $invoice['bank_details'] = $invoice['bank_details'] ? json_decode($invoice['bank_details'], true) : null;
        }

        responseJson([
            'status' => 'success',
            'data' => $invoices,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Tạo hóa đơn (POST /invoices)
function createInvoice() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hóa đơn'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['contract_id', 'branch_id', 'due_date', 'status']);
    $data = sanitizeInput($input);
    $contract_id = (int)$data['contract_id'];
    $branch_id = (int)$data['branch_id'];
    $due_date = $data['due_date'];
    $status = $data['status'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày đến hạn không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    if (!in_array($status, ['pending', 'paid', 'overdue'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    try {
        // Kiểm tra hợp đồng và chi nhánh
        checkResourceExists($pdo, 'contracts', $contract_id);
        checkResourceExists($pdo, 'branches', $branch_id);

        $stmt = $pdo->prepare("
            SELECT c.room_id, c.start_date, r.price
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            WHERE c.id = ? AND c.deleted_at IS NULL
        ");
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Hợp đồng không tồn tại'], 404);
            return;
        }

        $room_id = $contract['room_id'];
        $room_price = $contract['price'];
        $start_date = $contract['start_date'];

        // Kiểm tra quyền owner/employee
        $stmt = $pdo->prepare("
            SELECT 1 FROM branches b
            WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
            ))
        ");
        $stmt->execute([$branch_id, $user_id, $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hóa đơn cho chi nhánh này'], 403);
            return;
        }

        // Xác định tháng hiện tại và tỷ lệ sử dụng
        $current_date = new DateTime($due_date);
        $current_month = $current_date->format('Y-m');
        $start_date_obj = new DateTime($start_date);
        $days_in_month = (int)$current_date->format('t');
        $usage_days = 0;

        if ($start_date_obj->format('Y-m') === $current_month) {
            $interval = $start_date_obj->diff($current_date);
            $usage_days = max(1, $interval->days + 1); // Tối thiểu 1 ngày
        } else {
            $usage_days = (int)$current_date->format('j');
        }
        $usage_ratio = $usage_days / $days_in_month;

        // Kiểm tra utility_usage
        $stmt = $pdo->prepare("
            SELECT u.id, u.usage_amount, u.month, s.price
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.room_id = ? 
            AND u.month = ? 
            AND u.contract_id = ? 
            AND u.recorded_at >= ? 
            AND u.recorded_at <= ?
            AND u.deleted_at IS NULL
        ");
        $stmt->execute([$room_id, $current_month, $contract_id, $start_date, $due_date]);
        $utility_usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($utility_usages)) {
            responseJson([
                'status' => 'error',
                'message' => "Chưa có dữ liệu utility_usage cho hợp đồng $contract_id (phòng $room_id) trong tháng $current_month. Vui lòng cập nhật chỉ số điện/nước."
            ], 400);
            return;
        }

        $pdo->beginTransaction();

        // Tính tổng chi phí
        $amount_due = round($room_price * $usage_ratio);
        foreach ($utility_usages as $usage) {
            $amount_due += $usage['usage_amount'] * $usage['price'];
        }
        $amount_due = round($amount_due);

        // Tạo hóa đơn
        $stmt = $pdo->prepare("
            INSERT INTO invoices (contract_id, branch_id, amount, due_date, status, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$contract_id, $branch_id, $amount_due, $due_date, $status]);
        $invoice_id = $pdo->lastInsertId();

        // Lấy thông tin phòng
        $stmt = $pdo->prepare("
            SELECT r.name AS room_name
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            WHERE c.id = ?
        ");
        $stmt->execute([$contract_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        $room_name = $room['room_name'];

        $pdo->commit();

        createNotification(
            $pdo,
            $user_id,
            "Đã tạo hóa đơn (ID: $invoice_id, Tổng: $amount_due, Trạng thái: $status) cho phòng $room_name."
        );

        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details
            FROM invoices i
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        $invoice['bank_details'] = $invoice['bank_details'] ? json_decode($invoice['bank_details'], true) : null;

        responseJson([
            'status' => 'success',
            'message' => 'Tạo hóa đơn thành công',
            'data' => $invoice
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi tạo hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Lấy hóa đơn theo ID (GET /invoices/{id})
function getInvoiceById($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee', 'customer'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   c.room_id, r.name AS room_name, b.name AS branch_name, p.payment_date,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON i.branch_id = b.id
            LEFT JOIN payments p ON i.contract_id = p.contract_id AND i.due_date = p.due_date
            JOIN users u ON b.owner_id = u.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM branches b
                WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$invoice['branch_id'], $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn này'], 403);
                return;
            }
        } elseif ($role === 'customer') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM contracts c
                WHERE c.id = ? AND c.user_id = ?
            ");
            $stmt->execute([$invoice['contract_id'], $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn này'], 403);
                return;
            }
        }

        $invoice['bank_details'] = $invoice['bank_details'] ? json_decode($invoice['bank_details'], true) : null;

        responseJson([
            'status' => 'success',
            'data' => $invoice
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Lấy chi tiết hóa đơn (GET /invoices/{id}/details)
function getInvoiceDetails($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee', 'customer'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem chi tiết hóa đơn'], 403);
        return;
    }

    try {
        // Modified query to include customer details from users table
        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   c.room_id, c.user_id AS customer_id, r.name AS room_name, b.name AS branch_name,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details,
                   cu.name AS customer_name, cu.phone AS customer_phone, cu.email AS customer_email
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            JOIN users cu ON c.user_id = cu.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM branches b
                WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$invoice['branch_id'], $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn này'], 403);
                return;
            }
        } elseif ($role === 'customer') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM contracts c
                WHERE c.id = ? AND c.user_id = ?
            ");
            $stmt->execute([$invoice['contract_id'], $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn này'], 403);
                return;
            }
        }

        $month = date('Y-m', strtotime($invoice['due_date']));
        $stmt = $pdo->prepare("
            SELECT s.id AS service_id, s.name AS service_name, s.price, u.usage_amount, s.unit
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.room_id = ? AND u.contract_id = ? AND u.month = ? AND u.deleted_at IS NULL
        ");
        $stmt->execute([$invoice['room_id'], $invoice['contract_id'], $month]);
        $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $details = [
            [
                'service_id' => null,
                'amount' => (float)$invoice['amount'] - array_sum(array_map(function($usage) {
                    return $usage['usage_amount'] * $usage['price'];
                }, $usages)),
                'usage_amount' => null,
                'description' => "Tiền phòng ({$invoice['room_name']})",
                'service_name' => 'Room Price'
            ]
        ];

        foreach ($usages as $usage) {
            $details[] = [
                'service_id' => $usage['service_id'],
                'amount' => (float)($usage['usage_amount'] * $usage['price']),
                'unit' => $usage['unit'],
                'price' => (float)$usage['price'],
                'usage_amount' => (float)$usage['usage_amount'],
                'description' => "Tiền {$usage['service_name']} ({$usage['usage_amount']} {$usage['unit']})",
                'service_name' => $usage['service_name']
            ];
        }

        $invoice['bank_details'] = $invoice['bank_details'] ? json_decode($invoice['bank_details'], true) : null;
       
        if (!empty($invoice['qr_code_url'])) {
            // Lấy tên tệp từ URL
            $filename = basename($invoice['qr_code_url']);
            error_log("QR code filename: $filename");
            $imagePath = __DIR__ . '/../uploads/qr_codes/' . $filename;
            
            if (file_exists($imagePath)) {
                // Đọc nội dung tệp hình ảnh
                $imageData = file_get_contents($imagePath);
                if ($imageData !== false) {
                    // Chuyển đổi thành base64
                    $base64 = 'data:image/png;base64,' . base64_encode($imageData);
                    $invoice['qr_code_url'] = $base64; // Thay URL bằng chuỗi base64
                } else {
                    $invoice['qr_code_url'] = ''; // Nếu không đọc được tệp
                    error_log("Failed to read QR code image: $imagePath");
                }
            } else {
                $invoice['qr_code_url'] = ''; // Nếu tệp không tồn tại
                error_log("QR code image not found: $imagePath");
            }
        }

        // Create customer array
        $customer = [
            'id' => $invoice['customer_id'],
            'name' => $invoice['customer_name'],
            'phone' => $invoice['customer_phone'],
            'email' => $invoice['customer_email']
        ];

        // Remove customer fields from invoice array to avoid duplication
        unset($invoice['customer_id']);
        unset($invoice['customer_name']);
        unset($invoice['customer_phone']);
        unset($invoice['customer_email']);

        responseJson([
            'status' => 'success',
            'data' => [
                'invoice' => $invoice,
                'details' => $details,
                'customer' => $customer
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy chi tiết hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Tạo/làm mới hóa đơn hàng loạt (POST /invoices/bulk)
function createBulkInvoices() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hóa đơn'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['branch_id', 'month', 'due_date']);
    $data = sanitizeInput($input);
    $branch_id = (int)$data['branch_id'];
    $month = $data['month'];
    $due_date = $data['due_date'];

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ (YYYY-MM)'], 400);
        return;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày đến hạn không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    try {
        checkResourceExists($pdo, 'branches', $branch_id);

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM branches b
                WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$branch_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hóa đơn cho chi nhánh này'], 403);
                return;
            }
        }

        $pdo->beginTransaction();

        // Lấy danh sách hợp đồng
        $stmt = $pdo->prepare("
            SELECT c.id AS contract_id, c.room_id, c.start_date, r.price AS room_price, r.name AS room_name
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            WHERE r.branch_id = ? 
            AND r.status = 'occupied'
            AND ? BETWEEN DATE_FORMAT(c.start_date, '%Y-%m') AND IFNULL(DATE_FORMAT(c.end_date, '%Y-%m'), '9999-12')
            AND c.status IN ('active', 'ended', 'cancelled')
            AND c.deleted_at IS NULL
            AND EXISTS (
                SELECT 1 
                FROM utility_usage u
                JOIN services s ON u.service_id = s.id
                WHERE u.room_id = c.room_id 
                AND u.month = ? 
                AND u.contract_id = c.id
                AND u.recorded_at >= c.start_date
                AND u.recorded_at <= ?
                AND u.deleted_at IS NULL
            )
        ");
        $stmt->execute([$branch_id, $month, $month, $due_date]);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $created = [];
        $current_date = new DateTime($due_date);
        $current_month = $current_date->format('Y-m');
        $days_in_month = (int)$current_date->format('t');

        foreach ($contracts as $contract) {
            $contract_id = $contract['contract_id'];
            $room_id = $contract['room_id'];
            $room_price = $contract['room_price'];
            $room_name = $contract['room_name'];
            $start_date = $contract['start_date'];

            // Tính tỷ lệ sử dụng
            $start_date_obj = new DateTime($start_date);
            $usage_days = 0;
            if ($start_date_obj->format('Y-m') === $current_month) {
                $interval = $start_date_obj->diff($current_date);
                $usage_days = max(1, $interval->days + 1);
            } else {
                $usage_days = (int)$current_date->format('j');
            }
            $usage_ratio = $usage_days / $days_in_month;

            // Lấy dữ liệu utility_usage
            $stmt = $pdo->prepare("
                SELECT u.service_id, u.usage_amount, s.price, s.name AS service_name, s.unit
                FROM utility_usage u
                JOIN services s ON u.service_id = s.id
                WHERE u.room_id = ? AND u.month = ? AND u.contract_id = ? AND u.deleted_at IS NULL
            ");
            $stmt->execute([$room_id, $month, $contract_id]);
            $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($usages)) {
                continue; // Bỏ qua nếu không có dữ liệu utility_usage
            }

            // Tính tổng chi phí
            $total_amount = round($room_price * $usage_ratio);
            foreach ($usages as $usage) {
                $service_amount = $usage['usage_amount'] * $usage['price'];
                $total_amount += $service_amount;
            }
            $total_amount = round($total_amount);

            // Kiểm tra hóa đơn hiện có
            $stmt = $pdo->prepare("
                SELECT id FROM invoices
                WHERE contract_id = ? AND DATE_FORMAT(due_date, '%Y-%m') = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$contract_id, $month]);
            $existing_invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_invoice) {
                $stmt = $pdo->prepare("
                    UPDATE invoices
                    SET amount = ?, due_date = ?, status = 'pending', created_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$total_amount, $due_date, $existing_invoice['id']]);
                $invoice_id = $existing_invoice['id'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO invoices (contract_id, branch_id, amount, due_date, status, created_at)
                    VALUES (?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([$contract_id, $branch_id, $total_amount, $due_date]);
                $invoice_id = $pdo->lastInsertId();
            }

            $created[] = [
                'id' => $invoice_id,
                'contract_id' => $contract_id,
                'room_id' => $room_id,
                'branch_id' => $branch_id,
                'amount' => $total_amount,
                'due_date' => $due_date,
                'status' => 'pending'
            ];

            createNotification(
                $pdo,
                $user_id,
                "Đã tạo/cập nhật hóa đơn (ID: $invoice_id, Tổng: $total_amount) cho phòng $room_name, kỳ $month."
            );
        }

        $pdo->commit();

        responseJson([
            'status' => 'success',
            'message' => 'Tạo/làm mới hóa đơn thành công',
            'data' => [
                'count' => count($created),
                'invoices' => $created
            ]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi tạo hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Cập nhật hóa đơn (PUT /invoices/{id})
function updateInvoice($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hóa đơn'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['due_date', 'status']);
    $data = sanitizeInput($input);
    $due_date = $data['due_date'];
    $status = $data['status'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày đến hạn không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    if (!in_array($status, ['pending', 'paid', 'overdue'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    try {
        // Kiểm tra hóa đơn
        $stmt = $pdo->prepare("
            SELECT i.branch_id, i.contract_id, c.room_id, c.start_date, r.name AS room_name, r.price
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        $contract_id = $invoice['contract_id'];
        $room_id = $invoice['room_id'];
        $room_price = $invoice['price'];
        $start_date = $invoice['start_date'];
        $room_name = $invoice['room_name'];

        // Kiểm tra quyền owner/employee
        $stmt = $pdo->prepare("
            SELECT 1 FROM branches b
            WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
            ))
        ");
        $stmt->execute([$invoice['branch_id'], $user_id, $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hóa đơn này'], 403);
            return;
        }

        // Tính lại amount
        $current_date = new DateTime($due_date);
        $current_month = $current_date->format('Y-m');
        $start_date_obj = new DateTime($start_date);
        $days_in_month = (int)$current_date->format('t');
        $usage_days = 0;

        if ($start_date_obj->format('Y-m') === $current_month) {
            $interval = $start_date_obj->diff($current_date);
            $usage_days = max(1, $interval->days + 1);
        } else {
            $usage_days = (int)$current_date->format('j');
        }
        $usage_ratio = $usage_days / $days_in_month;

        // Kiểm tra utility_usage
        $stmt = $pdo->prepare("
            SELECT u.id, u.usage_amount, u.month, s.price
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.room_id = ? 
            AND u.month = ? 
            AND u.contract_id = ? 
            AND u.recorded_at >= ? 
            AND u.recorded_at <= ?
            AND u.deleted_at IS NULL
        ");
        $stmt->execute([$room_id, $current_month, $contract_id, $start_date, $due_date]);
        $utility_usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($utility_usages)) {
            responseJson([
                'status' => 'error',
                'message' => "Chưa có dữ liệu utility_usage cho hợp đồng $contract_id (phòng $room_id) trong tháng $current_month. Vui lòng cập nhật chỉ số điện/nước."
            ], 400);
            return;
        }

        // Tính tổng chi phí
        $amount_due = round($room_price * $usage_ratio);
        foreach ($utility_usages as $usage) {
            $amount_due += $usage['usage_amount'] * $usage['price'];
        }
        $amount_due = round($amount_due);

        $pdo->beginTransaction();

        // Cập nhật hóa đơn
        $stmt = $pdo->prepare("
            UPDATE invoices
            SET amount = ?, due_date = ?, status = ?, created_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$amount_due, $due_date, $status, $invoice_id]);

        // Cập nhật hoặc tạo bản ghi thanh toán nếu trạng thái là 'paid'
        if ($status === 'paid') {
            $stmt = $pdo->prepare("
                SELECT id FROM payments
                WHERE contract_id = ? AND due_date = ?
            ");
            $stmt->execute([$contract_id, $due_date]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET amount = ?, payment_date = CURDATE(), status = 'paid'
                    WHERE id = ?
                ");
                $stmt->execute([$amount_due, $payment['id']]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (contract_id, amount, due_date, payment_date, status, created_at)
                    VALUES (?, ?, ?, CURDATE(), 'paid', NOW())
                ");
                $stmt->execute([$contract_id, $amount_due, $due_date]);
            }
        }

        $pdo->commit();

        createNotification(
            $pdo,
            $user_id,
            "Đã cập nhật hóa đơn (ID: $invoice_id, Tổng: $amount_due, Trạng thái: $status) cho phòng $room_name."
        );

        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details
            FROM invoices i
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoice_id]);
        $updated_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        $updated_invoice['bank_details'] = $updated_invoice['bank_details'] ? json_decode($updated_invoice['bank_details'], true) : null;

        responseJson([
            'status' => 'success',
            'message' => 'Cập nhật hóa đơn thành công',
            'data' => $updated_invoice
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi cập nhật hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Cập nhật một phần hóa đơn (PATCH /invoices/{id})
function patchInvoice($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hóa đơn'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $data = sanitizeInput($input);

    $allowed_fields = ['amount', 'due_date', 'status'];
    $update_fields = [];
    $params = ['id' => $invoice_id];

    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $update_fields[] = "$field = :$field";
            $params[$field] = $data[$field];
        }
    }

    if (empty($update_fields)) {
        responseJson(['status' => 'error', 'message' => 'Không có trường nào được cung cấp để cập nhật'], 400);
        return;
    }

    if (isset($data['amount']) && (float)$data['amount'] < 0) {
        responseJson(['status' => 'error', 'message' => 'Tổng tiền không được âm'], 400);
        return;
    }

    if (isset($data['due_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['due_date'])) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày đến hạn không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    if (isset($data['status']) && !in_array($data['status'], ['pending', 'paid', 'overdue'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT i.branch_id, i.contract_id, c.room_id, r.name AS room_name
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT 1 FROM branches b
            WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
            ))
        ");
        $stmt->execute([$invoice['branch_id'], $user_id, $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hóa đơn này'], 403);
            return;
        }

        $pdo->beginTransaction();

        $query = "UPDATE invoices SET " . implode(', ', $update_fields) . ", created_at = NOW() WHERE id = :id";
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $pdo->commit();

        createNotification(
            $pdo,
            $user_id,
            "Đã cập nhật một phần hóa đơn (ID: $invoice_id) cho phòng {$invoice['room_name']}."
        );

        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details
            FROM invoices i
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoice_id]);
        $updated_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        $updated_invoice['bank_details'] = $updated_invoice['bank_details'] ? json_decode($updated_invoice['bank_details'], true) : null;

        responseJson([
            'status' => 'success',
            'message' => 'Cập nhật hóa đơn thành công',
            'data' => $updated_invoice
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi cập nhật hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Xóa mềm hóa đơn (DELETE /invoices/{id})
function deleteInvoice($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa hóa đơn'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT i.branch_id, i.contract_id, c.room_id, r.name AS room_name
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT 1 FROM branches b
            WHERE b.id = ? AND b.owner_id = ?
        ");
        $stmt->execute([$invoice['branch_id'], $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa hóa đơn này'], 403);
            return;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE invoices SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$invoice_id]);

        $pdo->commit();

        createNotification(
            $pdo,
            $user_id,
            "Đã xóa hóa đơn (ID: $invoice_id) cho phòng {$invoice['room_name']}."
        );

        responseJson([
            'status' => 'success',
            'message' => 'Xóa hóa đơn thành công',
            'data' => ['id' => $invoice_id]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi xóa hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>