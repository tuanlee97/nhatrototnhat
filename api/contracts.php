<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Lấy danh sách hợp đồng
function getContracts() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    // Xử lý quyền truy cập dựa trên vai trò
    if ($role === 'customer') {
        // Khách hàng chỉ xem được hợp đồng của chính họ
        $conditions[] = "c.user_id = ?";
        $params[] = $user_id;
    } elseif ($role === 'owner') {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE owner_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $branch_id = $stmt->fetchColumn();
        if ($branch_id) {
            $conditions[] = "c.branch_id = ?";
            $params[] = $branch_id;
        }
    } elseif ($role === 'employee') {
        $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $branch_id = $stmt->fetchColumn();
        if ($branch_id) {
            $conditions[] = "c.branch_id = ?";
            $params[] = $branch_id;
        }
    } elseif ($role !== 'admin') {
        // Các vai trò khác (ngoại trừ admin) chỉ xem hợp đồng của chi nhánh thuộc sở hữu
        $conditions[] = "c.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
        $params[] = $user_id;
    }

    // Xử lý branch_id từ query string (chỉ áp dụng cho admin hoặc khi cần lọc cụ thể)
    if (!empty($_GET['branch_id']) && $role === 'admin') {
        $branch_id = (int)$_GET['branch_id'];
        $conditions[] = "c.branch_id = ?";
        $params[] = $branch_id;
    }

    if (!empty($_GET['status'])) {
        $conditions[] = "c.status = ?";
        $params[] = $_GET['status'];
    }

    if (!empty($_GET['search'])) {
        $conditions[] = "(c.id LIKE ? OR u.username LIKE ? OR r.name LIKE ?)";
        $search = '%' . $_GET['search'] . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $conditions[] = "c.deleted_at IS NULL";

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT c.id, c.room_id, c.user_id, c.start_date, c.end_date, c.status, c.created_at, c.deposit,
               c.branch_id, r.name AS room_name, u.username AS user_name, b.name AS branch_name
        FROM contracts c
        JOIN rooms r ON c.room_id = r.id
        JOIN users u ON c.user_id = u.id
        JOIN branches b ON c.branch_id = b.id
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN users u ON c.user_id = u.id JOIN branches b ON c.branch_id = b.id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $contracts,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function checkBranchHasEssentialServices(PDO $pdo, int $branchId): bool {
    $stmt = $pdo->prepare("
        SELECT type 
        FROM services 
        WHERE branch_id = ? AND deleted_at IS NULL AND type IN ('electricity', 'water')
    ");
    $stmt->execute([$branchId]);
    $types = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return in_array('electricity', $types) && in_array('water', $types);
}

// Tạo hợp đồng
function createContract() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hợp đồng'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'user_id', 'start_date', 'end_date', 'branch_id']);
    $data = sanitizeInput($input);

    try {
        // Kiểm tra quyền truy cập
        checkResourceExists($pdo, 'rooms', $data['room_id']);
        checkResourceExists($pdo, 'users', $data['user_id']);
        checkResourceExists($pdo, 'branches', $data['branch_id']);

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM rooms r 
                JOIN branches b ON r.branch_id = b.id 
                WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea 
                    WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$data['room_id'], $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hợp đồng cho phòng này'], 403);
                return;
            }
        }

        // Kiểm tra phòng có đang available
        $stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
        $stmt->execute([$data['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($room['status'] !== 'available') {
            responseJson(['status' => 'error', 'message' => 'Phòng không khả dụng'], 400);
            return;
        }

        // Kiểm tra phòng có hợp đồng còn hiệu lực không
        $stmt = $pdo->prepare("SELECT 1 FROM contracts WHERE room_id = ? AND status = 'active' AND end_date > NOW()");
        $stmt->execute([$data['room_id']]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng đã có hợp đồng đang hoạt động'], 400);
            return;
        }
        if (!checkBranchHasEssentialServices($pdo, $data['branch_id'])) {
            responseJson([
                'status' => 'error',
                'message' => 'Chi nhánh phải có đầy đủ cả hai dịch vụ: điện và nước trước khi tạo hợp đồng'
            ], 400);
            return;
        }
        // Tạo hợp đồng
        $stmt = $pdo->prepare("
            INSERT INTO contracts 
            (room_id, user_id, start_date, end_date, status, created_at, created_by, branch_id, deposit) 
            VALUES (?, ?, ?, ?, 'active', NOW(), ?, ?, ?)
        ");
        $stmt->execute([
            $data['room_id'],
            $data['user_id'],
            $data['start_date'],
            $data['end_date'],
            $user_id,
            $data['branch_id'],
            $data['deposit'] ?? 0
        ]);
        $contractId = $pdo->lastInsertId();

        // Cập nhật trạng thái phòng
        $stmt = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?");
        $stmt->execute([$data['room_id']]);

        createNotification($pdo, $data['user_id'], "Hợp đồng thuê phòng ID $contractId đã được tạo.");
        responseJson(['status' => 'success', 'message' => 'Tạo hợp đồng thành công', 'data' => ['id' => $contractId]]);
    } catch (PDOException $e) {
        error_log("Lỗi tạo hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Cập nhật hợp đồng
function updateContract() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hợp đồng'], 403);
        return;
    }

    // Nhận dữ liệu đầu vào
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['id', 'room_id', 'user_id', 'start_date', 'end_date', 'branch_id']);
    $data = sanitizeInput($input);

    try {
        // Kiểm tra quyền truy cập hợp đồng
        checkResourceExists($pdo, 'contracts', $data['id']);
        checkResourceExists($pdo, 'rooms', $data['room_id']);
        checkResourceExists($pdo, 'users', $data['user_id']);
        checkResourceExists($pdo, 'branches', $data['branch_id']);

        // Kiểm tra quyền của owner/employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM contracts c 
                JOIN rooms r ON c.room_id = r.id 
                JOIN branches b ON c.branch_id = b.id 
                WHERE c.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea 
                    WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$data['id'], $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hợp đồng này'], 403);
                return;
            }
        }

        // Lấy thông tin hợp đồng từ database
        $stmt = $pdo->prepare("SELECT room_id FROM contracts WHERE id = ?");
        $stmt->execute([$data['id']]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Hợp đồng không tồn tại'], 404);
            return;
        }

        $current_room_id = $contract['room_id'];


        // Nếu room_id thay đổi, kiểm tra phòng mới có khả dụng không
        if ((int)$data['room_id'] !== $current_room_id) {
            // Kiểm tra phòng mới có sẵn không
            $stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
            $stmt->execute([$data['room_id']]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($room['status'] !== 'available') {
                responseJson(['status' => 'error', 'message' => 'Phòng không khả dụng'], 400);
                return;
            }

            // Cập nhật trạng thái phòng hiện tại về 'available'
            $stmt = $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = ?");
            $stmt->execute([$current_room_id]);

            // Cập nhật trạng thái phòng mới về 'occupied'
            $stmt = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?");
            $stmt->execute([$data['room_id']]);

            // Cập nhật room_occupants: xóa người thuê khỏi phòng cũ
            $stmt = $pdo->prepare("UPDATE room_occupants SET deleted_at = NOW() WHERE room_id = ? AND user_id = ?");
            $stmt->execute([$current_room_id, $data['user_id']]);

            // Thêm người thuê vào phòng mới
            $stmt = $pdo->prepare("INSERT INTO room_occupants (room_id, user_id, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$data['room_id'], $data['user_id']]);
        }

        // Cập nhật hợp đồng
        $stmt = $pdo->prepare("
            UPDATE contracts 
            SET room_id = ?, user_id = ?, start_date = ?, end_date = ?, 
                status = ?, deposit = ?, branch_id = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['room_id'],
            $data['user_id'],
            $data['start_date'],
            $data['end_date'],
            $data['status'] ?? 'active',
            $data['deposit'] ?? 0,
            $data['branch_id'],
            $data['id']
        ]);

        // Gửi thông báo
        createNotification($pdo, $data['user_id'], "Hợp đồng ID {$data['id']} đã được cập nhật.");

        // Trả về kết quả thành công
        responseJson(['status' => 'success', 'message' => 'Cập nhật hợp đồng thành công', 'data' => $data]);
    } catch (PDOException $e) {
        error_log("Lỗi cập nhật hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}


// Xóa hợp đồng (xóa mềm)
function deleteContract() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa hợp đồng'], 403);
        return;
    }

    // Nhận dữ liệu đầu vào
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['id']);
    $contract_id = (int)$input['id'];

    try {
        // Kiểm tra hợp đồng có tồn tại
        checkResourceExists($pdo, 'contracts', $contract_id);

        // Kiểm tra quyền xóa hợp đồng nếu người dùng là owner
        if ($role === 'owner') {
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM contracts c 
                JOIN branches b ON c.branch_id = b.id 
                WHERE c.id = ? AND b.owner_id = ?
            ");
            $stmt->execute([$contract_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa hợp đồng này'], 403);
                return;
            }
        }

        // Lấy thông tin hợp đồng trước khi xóa
        $stmt = $pdo->prepare("SELECT room_id, user_id FROM contracts WHERE id = ?");
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Hợp đồng không tồn tại'], 404);
            return;
        }

        // Bắt đầu giao dịch
        $pdo->beginTransaction();

        // 1. Cập nhật trạng thái hợp đồng thành 'deleted' và đánh dấu thời gian xóa
        $stmt = $pdo->prepare("UPDATE contracts SET status = 'deleted', deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$contract_id]);

        // 2. Cập nhật trạng thái phòng thành 'available'
        $stmt = $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = ?");
        $stmt->execute([$contract['room_id']]);

        // 3. Đánh dấu người thuê phòng (room_occupants) là đã xóa
        $stmt = $pdo->prepare("UPDATE room_occupants SET deleted_at = NOW() WHERE room_id = ?");
        $stmt->execute([$contract['room_id']]);

        // 4. Gửi thông báo cho người thuê
        createNotification($pdo, $contract['user_id'], "Hợp đồng ID $contract_id đã bị xóa.");

        // Cam kết giao dịch
        $pdo->commit();

        // Trả về thông báo thành công
        responseJson(['status' => 'success', 'message' => 'Xóa hợp đồng thành công']);
    } catch (PDOException $e) {
        // Quay lại giao dịch trong trường hợp lỗi
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi xóa hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Xóa hợp đồng và các liên kết liên quan bằng cách cập nhật trường deleted_at
function deleteContractById($contract) {
    $pdo = getDB();
    $contract_id = $contract['id'];
    $room_id = $contract['room_id'];
    try {
        // Xóa mềm hợp đồng
        $stmt = $pdo->prepare("UPDATE contracts SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$contract_id]);

        // Xóa mềm người thuê (room_occupants)
        $stmt = $pdo->prepare("UPDATE utility_usage SET deleted_at = NOW() WHERE room_id = ? AND contract_id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id, $contract_id]);

        // Xóa mềm các tiện ích sử dụng (utility_usage)
        $stmt = $pdo->prepare("UPDATE utility_usage SET deleted_at = NOW() WHERE contract_id = ?");
        $stmt->execute([$contract_id]);

    } catch (PDOException $e) {
        error_log("Lỗi xóa hợp đồng ID $contract_id: " . $e->getMessage());
    }
}

// Kết thúc hợp đồng
function endContract() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền kết thúc hợp đồng'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['id']);
    $contract_id = (int)$input['id'];
    
    try {
        // Lấy thông tin hợp đồng
        $stmt = $pdo->prepare("
            SELECT c.room_id, c.user_id, c.status, c.start_date, c.end_date, c.deposit, c.branch_id, r.price
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            WHERE c.id = ? AND c.deleted_at IS NULL
        ");
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Hợp đồng không tồn tại hoặc đã bị xóa'], 404);
            return;
        }
        if ($contract['status'] !== 'active') {
            deleteContract($contract);
            responseJson(['status' => 'success', 'message' => 'Đã xóa hợp đồng']);
            return;
        }
        $room_id = $contract['room_id'];
        $tenant_id = $contract['user_id'];
        $branch_id = $contract['branch_id'];
        $deposit = $contract['deposit'];
        $room_price = $contract['price'];
        $start_date = $contract['start_date'];

        // Kiểm tra quyền owner/employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM contracts c
                JOIN branches b ON c.branch_id = b.id
                WHERE c.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                )) AND c.deleted_at IS NULL
            ");
            $stmt->execute([$contract_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền kết thúc hợp đồng này'], 403);
                return;
            }
        }

        // Xác định tháng hiện tại và tỷ lệ sử dụng
        $current_date = new DateTime();
        $current_month = $current_date->format('Y-m');
        $start_date_obj = new DateTime($start_date);
        $days_in_month = (int)$current_date->format('t');

        // Tính số ngày sử dụng thực tế, tối thiểu là 1 ngày
        $usage_days = 0;
        if ($start_date_obj->format('Y-m') === $current_month) {
            // Nếu hợp đồng bắt đầu trong tháng hiện tại
            $interval = $start_date_obj->diff($current_date);
            $usage_days = max(1, $interval->days + 1); // Tối thiểu 1 ngày, +1 để tính cả ngày hiện tại
        } else {
            // Nếu hợp đồng bắt đầu trước tháng hiện tại
            $usage_days = (int)$current_date->format('j'); // Số ngày từ đầu tháng đến hiện tại
        }
        $usage_ratio = $usage_days / $days_in_month;

        // Kiểm tra utility_usage cho hợp đồng hiện tại
        $stmt = $pdo->prepare("
            SELECT u.id, u.usage_amount, u.month, s.price
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.room_id = ? 
            AND u.month = ? 
            AND u.contract_id = ? 
            AND u.recorded_at >= ? 
            AND u.recorded_at <= NOW()
            AND u.deleted_at IS NULL
        ");
        $stmt->execute([$room_id, $current_month, $contract_id, $start_date]);
        $utility_usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($utility_usages)) {
            responseJson([
                'status' => 'error',
                'message' => "Chưa có dữ liệu utility_usage cho hợp đồng $contract_id (phòng $room_id) trong tháng $current_month. Vui lòng cập nhật chỉ số điện/nước."
            ], 400);
            return;
        }

        $pdo->beginTransaction();

        // 1. Cập nhật trạng thái hợp đồng
        $stmt = $pdo->prepare("UPDATE contracts SET status = 'ended', end_date = NOW() WHERE id = ?");
        $stmt->execute([$contract_id]);

        // 2. Cập nhật trạng thái phòng
        $stmt = $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = ?");
        $stmt->execute([$room_id]);

        // 3. Tạo hóa đơn
        $amount_due = 0;
        foreach ($utility_usages as $usage) {
            $amount_due += $usage['usage_amount'] * $usage['price'];
        }
        $amount_due += round($room_price * $usage_ratio);
        $amount_due = round($amount_due);
        $today = date('Y-m-d');
    
        $stmt = $pdo->prepare("
            INSERT INTO invoices (contract_id, branch_id, amount, due_date, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$contract_id, $branch_id, $amount_due, $today]);
        $invoice_id = $pdo->lastInsertId();

        // 6. Tạo bản ghi thanh toán
        $stmt = $pdo->prepare("
            INSERT INTO payments (contract_id, amount, due_date, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$contract_id, $amount_due, $today]);

        // 7. Gửi thông báo
        if ($deposit > 0) {
            createNotification($pdo, $tenant_id, "Tiền đặt cọc $deposit cho hợp đồng ID $contract_id đã được hoàn.");
        }
        $notification_message = "Hợp đồng ID $contract_id đã được kết thúc. Hóa đơn ID $invoice_id đã được tạo cho $current_day/$days_in_month ngày sử dụng trong tháng $current_month.";
        createNotification($pdo, $tenant_id, $notification_message);

        $pdo->commit();

        responseJson([
            'status' => 'success',
            'message' => 'Kết thúc hợp đồng thành công',
            'data' => ['invoice_id' => $invoice_id]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi kết thúc hợp đồng (contract ID $contract_id): " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}