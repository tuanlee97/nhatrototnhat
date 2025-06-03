<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Lấy danh sách phòng
function getRooms() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if ($role === 'customer') {
        // Khách hàng chỉ xem phòng của hợp đồng active
        $conditions[] = "c.user_id = ?";
        $params[] = $user_id;
        $conditions[] = "c.status = 'active'";
    } elseif ($role === 'admin' && !empty($_GET['branch_id'])) {
        // Admin có thể lọc theo branch_id
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
        $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$user_id]);
        $branch_id = $stmt->fetchColumn();
        if ($branch_id) {
            $conditions[] = "r.branch_id = ?";
            $params[] = $branch_id;
        }
    } else {
        if ($role !== 'admin') {
            $conditions[] = "r.branch_id IN (SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL)";
            $params[] = $user_id;
        }
    }

    if (!empty($_GET['status'])) {
        $conditions[] = "r.status = ?";
        $params[] = $_GET['status'];
    }

    if (!empty($_GET['search'])) {
        $conditions[] = "(r.name LIKE ? OR rt.name LIKE ? OR b.name LIKE ?)";
        $search = '%' . $_GET['search'] . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $conditions[] = "r.deleted_at IS NULL";
    $conditions[] = "rt.deleted_at IS NULL";
    $conditions[] = "b.deleted_at IS NULL";

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $joinType = ($role === 'customer') ? "INNER JOIN" : "LEFT JOIN";
    $query = "
        SELECT 
            r.id, 
            r.branch_id, 
            r.type_id, 
            r.name, 
            r.price, 
            r.status, 
            r.created_at,
            rt.name AS type_name, 
            b.name AS branch_name,
            c.id AS contract_id
        FROM rooms r
        JOIN room_types rt ON r.type_id = rt.id
        JOIN branches b ON r.branch_id = b.id
        $joinType contracts c ON r.id = c.room_id AND c.status = 'active' AND c.deleted_at IS NULL
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM rooms r 
            JOIN room_types rt ON r.type_id = rt.id 
            JOIN branches b ON r.branch_id = b.id 
            $joinType contracts c ON r.id = c.room_id AND c.status = 'active' AND c.deleted_at IS NULL 
            $whereClause
        ");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $rooms,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
// Tạo phòng
function createRoom() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo phòng'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    error_log("Input data: " . json_encode($input));
    validateRequiredFields($input, ['branch_id', 'type_id', 'name']);
    $data = sanitizeInput($input);

    if ($role === 'owner') {
        $stmt = $pdo->prepare("SELECT 1 FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
        $stmt->execute([$data['branch_id'], $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo phòng cho chi nhánh này'], 403);
            return;
        }
    }

    try {
        checkResourceExists($pdo, 'branches', $data['branch_id']);
        checkResourceExists($pdo, 'room_types', $data['type_id']);
        $stmt = $pdo->prepare("INSERT INTO rooms (branch_id, type_id, name, price, status, created_at) VALUES (?, ?, ?, ?, 'available', NOW())");
        $stmt->execute([$data['branch_id'], $data['type_id'], $data['name'], $data['price'] ?? 0]);
        responseJson(['status' => 'success', 'message' => 'Tạo phòng thành công']);
    } catch (PDOException $e) {
        error_log("Lỗi tạo phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Cập nhật thông tin phòng (bao gồm cả trạng thái)
function updateRoom() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $room_id = getResourceIdFromUri('#/rooms/([0-9]+)#');

    // Kiểm tra quyền truy cập
    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật phòng'], 403);
        return;
    }

    // Lấy dữ liệu đầu vào từ request
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("Input data: " . json_encode($input));

    // Xác định các trường bắt buộc và tùy chọn
    $requiredFields = [];
    if (isset($input['status']) || isset($input['branch_id']) || isset($input['type_id']) || isset($input['name'])) {
        $requiredFields = ['branch_id', 'type_id', 'name'];
        validateRequiredFields($input, $requiredFields);
    } elseif (isset($input['status'])) {
        validateRequiredFields($input, ['status']);
    } else {
        responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        return;
    }

    $data = sanitizeInput($input);

    // Validate status nếu có
    if (isset($data['status']) && !in_array($data['status'], ['available', 'occupied', 'maintenance'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    try {
        // Kiểm tra sự tồn tại của phòng
        $stmt = $pdo->prepare("SELECT 1 FROM rooms WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không tồn tại hoặc đã bị xóa'], 404);
            return;
        }

        // Kiểm tra quyền sở hữu chi nhánh nếu là owner hoặc employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("SELECT 1 FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ? AND r.deleted_at IS NULL AND b.deleted_at IS NULL");
            $stmt->execute([$room_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật phòng này'], 403);
                return;
            }
        }

        // Chuẩn bị câu lệnh UPDATE
        $setClause = [];
        $params = [];
        if (isset($data['branch_id'])) {
            checkResourceExists($pdo, 'branches', $data['branch_id']);
            $setClause[] = "branch_id = ?";
            $params[] = $data['branch_id'];
        }
        if (isset($data['type_id'])) {
            checkResourceExists($pdo, 'room_types', $data['type_id']);
            $setClause[] = "type_id = ?";
            $params[] = $data['type_id'];
        }
        if (isset($data['name'])) {
            $setClause[] = "name = ?";
            $params[] = $data['name'];
        }
        if (isset($data['price'])) {
            $setClause[] = "price = ?";
            $params[] = $data['price'] ?? 0;
        }
        if (isset($data['status'])) {
            $setClause[] = "status = ?";
            $params[] = $data['status'];
        }

        if (empty($setClause)) {
            responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
            return;
        }

        $params[] = $room_id;
        $query = "UPDATE rooms SET " . implode(", ", $setClause) . " WHERE id = ? AND deleted_at IS NULL";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        responseJson(['status' => 'success', 'message' => 'Cập nhật phòng thành công']);
    } catch (PDOException $e) {
        error_log("Lỗi cập nhật phòng ID $room_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Xóa mềm phòng
function deleteRoom() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $room_id = getResourceIdFromUri('#/rooms/([0-9]+)#');

    // Kiểm tra quyền xóa
    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa phòng'], 403);
        return;
    }

    try {
        // Kiểm tra sự tồn tại của phòng
        $stmt = $pdo->prepare("SELECT 1 FROM rooms WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không tồn tại hoặc đã bị xóa'], 404);
            return;
        }

        // Nếu là owner, kiểm tra quyền sở hữu chi nhánh
        if ($role === 'owner') {
            $stmt = $pdo->prepare("SELECT 1 FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ? AND r.deleted_at IS NULL AND b.deleted_at IS NULL");
            $stmt->execute([$room_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa phòng này'], 403);
                return;
            }
        }

        // Bắt đầu transaction để đảm bảo tính toàn vẹn dữ liệu
        $pdo->beginTransaction();

        // Xóa mềm các hợp đồng liên quan
        $stmt = $pdo->prepare("UPDATE contracts SET deleted_at = NOW() WHERE room_id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Xóa mềm các bản ghi trong utility_usage
        $stmt = $pdo->prepare("UPDATE utility_usage SET deleted_at = NOW() WHERE room_id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Xóa mềm các yêu cầu bảo trì
        $stmt = $pdo->prepare("UPDATE maintenance_requests SET deleted_at = NOW() WHERE room_id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Xóa mềm các thông tin người ở trong phòng
        $stmt = $pdo->prepare("UPDATE room_occupants SET deleted_at = NOW() WHERE room_id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Xóa mềm phòng
        $stmt = $pdo->prepare("UPDATE rooms SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Commit transaction
        $pdo->commit();

        responseJson(['status' => 'success', 'message' => 'Xóa mềm phòng thành công']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi xóa mềm phòng ID $room_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
function changeRoom() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền đổi phòng'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['contract_id', 'new_room_id', 'start_date', 'end_date', 'branch_id']);
    $data = sanitizeInput($input);
    $contract_id = (int)$data['contract_id'];
    $new_room_id = (int)$data['new_room_id'];
    $branch_id = (int)$data['branch_id'];

    try {
        // Lấy thông tin hợp đồng hiện tại
        $stmt = $pdo->prepare("
            SELECT c.room_id, c.user_id, c.branch_id, c.status, c.deposit, c.start_date, c.end_date, r.price
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

        $current_room_id = $contract['room_id'];
        $tenant_id = $contract['user_id'];
        $current_branch_id = $contract['branch_id'];
        $deposit = $contract['deposit'];
        $room_price = $contract['price'];
        $start_date = $contract['start_date'];

        // Kiểm tra quyền owner/employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM contracts c
                JOIN branches b ON c.branch_id = b.id
                WHERE c.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                )) AND c.deleted_at IS NULL
            ");
            $stmt->execute([$contract_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền đổi phòng cho hợp đồng này'], 403);
                return;
            }
        }

        // Kiểm tra phòng mới
        $stmt = $pdo->prepare("SELECT status, branch_id FROM rooms WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$new_room_id]);
        $new_room = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$new_room || $new_room['status'] !== 'available') {
            responseJson(['status' => 'error', 'message' => 'Phòng mới không tồn tại hoặc không khả dụng'], 400);
            return;
        }

        if ($new_room['branch_id'] !== $branch_id || $branch_id !== $current_branch_id) {
            responseJson(['status' => 'error', 'message' => 'Phòng mới phải thuộc cùng chi nhánh với hợp đồng hiện tại'], 400);
            return;
        }

        // Xác định tháng hiện tại và tỷ lệ sử dụng
        $current_date = new DateTime();
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
            AND u.recorded_at <= NOW()
            AND u.deleted_at IS NULL
        ");
        $stmt->execute([$current_room_id, $current_month, $contract_id, $start_date]);
        $utility_usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($utility_usages)) {
            responseJson([
                'status' => 'error',
                'message' => "Chưa có dữ liệu utility_usage cho hợp đồng $contract_id (phòng $current_room_id) trong tháng $current_month. Vui lòng cập nhật chỉ số điện/nước."
            ], 400);
            return;
        }

        $pdo->beginTransaction();

        // 1. Kết thúc hợp đồng hiện tại
        $stmt = $pdo->prepare("UPDATE contracts SET status = 'ended', end_date = NOW(), deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$contract_id]);

        // 2. Cập nhật trạng thái phòng hiện tại
        $stmt = $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = ?");
        $stmt->execute([$current_room_id]);

        // 3. Xóa mềm room_occupants
        $stmt = $pdo->prepare("UPDATE room_occupants SET deleted_at = NOW() WHERE room_id = ?");
        $stmt->execute([$current_room_id]);

        // 4. Xóa mềm utility_usage liên quan
        $stmt = $pdo->prepare("UPDATE utility_usage SET deleted_at = NOW() WHERE room_id = ? AND contract_id = ? AND deleted_at IS NULL");
        $stmt->execute([$current_room_id, $contract_id]);

        // 5. Tính hóa đơn
        $amount_due = round($room_price * $usage_ratio);
        foreach ($utility_usages as $usage) {
            $amount_due += $usage['usage_amount'] * $usage['price'];
        }
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

        // 7. Tạo hợp đồng mới
        $stmt = $pdo->prepare("
            INSERT INTO contracts (room_id, user_id, start_date, end_date, status, created_at, created_by, branch_id, deposit)
            VALUES (?, ?, ?, ?, 'active', NOW(), ?, ?, ?)
        ");
        $stmt->execute([
            $new_room_id,
            $tenant_id,
            $data['start_date'],
            $data['end_date'],
            $user_id,
            $branch_id,
            $data['deposit'] ?? $deposit
        ]);
        $new_contract_id = $pdo->lastInsertId();

        // 8. Cập nhật trạng thái phòng mới
        $stmt = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?");
        $stmt->execute([$new_room_id]);

        // 9. Thêm bản ghi room_occupants
        $stmt = $pdo->prepare("INSERT INTO room_occupants (room_id, user_id, start_date, end_date, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$new_room_id, $tenant_id, $data['start_date'], $data['end_date']]);

        // 10. Gửi thông báo
        $notification_message = "Hợp đồng ID $contract_id đã kết thúc. Hợp đồng mới ID $new_contract_id đã được tạo. Hóa đơn ID $invoice_id đã được tạo cho $usage_days/$days_in_month ngày sử dụng trong tháng $current_month.";
        createNotification($pdo, $tenant_id, $notification_message);

        $pdo->commit();

        responseJson([
            'status' => 'success',
            'message' => 'Đổi phòng thành công',
            'data' => [
                'new_contract_id' => $new_contract_id,
                'invoice_id' => $invoice_id
            ]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi đổi phòng (contract ID $contract_id): " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}