<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Tạo hoặc cập nhật số điện, nước (POST /utility_usage)
function createUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền nhập số điện, nước'], 403);
        return;
    }

    // Nhận dữ liệu đầu vào
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'service_id', 'month', 'old_reading', 'new_reading', 'record_date']);
    $data = sanitizeInput($input);
    $room_id = (int)$data['room_id'];
    $service_id = (int)$data['service_id'];
    $month = $data['month'];
    $old_reading = (float)$data['old_reading'];
    $new_reading = (float)$data['new_reading'];
    $usage_amount = $new_reading - $old_reading;
    $record_date = $data['record_date'];

    // Kiểm tra định dạng tháng (YYYY-MM)
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ (YYYY-MM)'], 400);
        return;
    }

    // Kiểm tra định dạng record_date (YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $record_date)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày ghi nhận không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    // Kiểm tra usage_amount, old_reading, new_reading
    if ($usage_amount < 0 || $old_reading < 0 || $new_reading < 0) {
        responseJson(['status' => 'error', 'message' => 'Giá trị không được âm'], 400);
        return;
    }

    if ($new_reading < $old_reading) {
        responseJson(['status' => 'error', 'message' => 'Số mới phải lớn hơn hoặc bằng số cũ'], 400);
        return;
    }

    if (abs($usage_amount - ($new_reading - $old_reading)) > 0.01) {
        responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng phải bằng số mới trừ số cũ'], 400);
        return;
    }

    try {
        // Kiểm tra phòng và dịch vụ tồn tại
        checkResourceExists($pdo, 'rooms', $room_id);
        checkResourceExists($pdo, 'services', $service_id);

        // Kiểm tra dịch vụ là điện, nước hoặc khác
        $stmt = $pdo->prepare("SELECT type, name, branch_id FROM services WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$service_id]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$service || !in_array($service['type'], ['electricity', 'water', 'other'])) {
            responseJson(['status' => 'error', 'message' => 'Dịch vụ phải là điện, nước hoặc khác'], 400);
            return;
        }

        // Lấy hợp đồng active hiện tại cho phòng
        $stmt = $pdo->prepare("
            SELECT id, branch_id
            FROM contracts
            WHERE room_id = ? 
            AND status = 'active'
            AND deleted_at IS NULL
            ORDER BY start_date DESC
            LIMIT 1
        ");
        $stmt->execute([$room_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy hợp đồng active cho phòng này'], 400);
            return;
        }
        $contract_id = $contract['id'];

        // Kiểm tra quyền owner/employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM rooms r
                JOIN branches b ON r.branch_id = b.id
                WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$room_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền nhập liệu cho phòng này'], 403);
                return;
            }
        }

        // Kiểm tra chi nhánh của phòng và dịch vụ
        $stmt = $pdo->prepare("SELECT branch_id FROM rooms WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);
        $room_branch = $stmt->fetchColumn();
        if ($room_branch !== $contract['branch_id'] || $room_branch !== $service['branch_id']) {
            responseJson(['status' => 'error', 'message' => 'Phòng, hợp đồng hoặc dịch vụ không thuộc cùng chi nhánh'], 400);
            return;
        }

        // Kiểm tra chỉ số trước đó
        $stmt = $pdo->prepare("
            SELECT new_reading 
            FROM utility_usage 
            WHERE room_id = ? 
            AND service_id = ? 
            AND month < ? 
            AND deleted_at IS NULL 
            ORDER BY recorded_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$room_id, $service_id, $month]);
        $previous_reading = $stmt->fetchColumn();
        if ($previous_reading !== false && $old_reading < $previous_reading) {
            responseJson(['status' => 'error', 'message' => "Số cũ ($old_reading) phải lớn hơn hoặc bằng số mới trước đó ($previous_reading)"], 400);
            return;
        }

        $pdo->beginTransaction();

        // Kiểm tra bản ghi đã tồn tại
        $stmt = $pdo->prepare("
            SELECT id FROM utility_usage
            WHERE room_id = ? AND contract_id = ? AND service_id = ? AND month = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$room_id, $contract_id, $service_id, $month]);
        $existing_usage = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_usage) {
            // Cập nhật bản ghi hiện có
            $stmt = $pdo->prepare("
                UPDATE utility_usage
                SET usage_amount = ?, old_reading = ?, new_reading = ?, recorded_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$usage_amount, $old_reading, $new_reading, $record_date, $existing_usage['id']]);
            $usage_id = $existing_usage['id'];
            $action = 'cập nhật';
        } else {
            // Tạo bản ghi mới
            $stmt = $pdo->prepare("
                INSERT INTO utility_usage (room_id, contract_id, service_id, month, usage_amount, old_reading, new_reading, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$room_id, $contract_id, $service_id, $month, $usage_amount, $old_reading, $new_reading, $record_date]);
            $usage_id = $pdo->lastInsertId();
            $action = 'nhập';
        }

        $pdo->commit();

        // Gửi thông báo
        createNotification(
            $pdo,
            $user_id,
            "Đã $action số {$service['name']} (Số cũ: $old_reading, Số mới: $new_reading, Dùng: $usage_amount) cho phòng $room_id, hợp đồng $contract_id, tháng $month."
        );

        responseJson([
            'status' => 'success',
            'message' => "Nhập số {$service['name']} thành công",
            'data' => [
                'id' => $usage_id,
                'room_id' => $room_id,
                'contract_id' => $contract_id,
                'service_id' => $service_id,
                'month' => $month,
                'usage_amount' => $usage_amount,
                'old_reading' => $old_reading,
                'new_reading' => $new_reading,
                'record_date' => $record_date,
                'service_name' => $service['name']
            ]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi nhập số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Cập nhật số điện, nước (PUT /utility_usage/{id})
function updateUtilityUsage($usage_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật số điện, nước'], 403);
        return;
    }

    // Nhận dữ liệu đầu vào
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'month', 'old_reading', 'new_reading', 'record_date']);
    $data = sanitizeInput($input);
    $room_id = (int)$data['room_id'];
    $month = $data['month'];
    $old_reading = (float)$data['old_reading'];
    $new_reading = (float)$data['new_reading'];
    $usage_amount = $new_reading - $old_reading;
    $record_date = $data['record_date'];

    // Kiểm tra định dạng tháng và record_date
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ (YYYY-MM)'], 400);
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $record_date)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày ghi nhận không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    // Kiểm tra usage_amount, old_reading, new_reading
    if ($usage_amount < 0 || $old_reading < 0 || $new_reading < 0) {
        responseJson(['status' => 'error', 'message' => 'Giá trị không được âm'], 400);
        return;
    }
    if ($new_reading < $old_reading) {
        responseJson(['status' => 'error', 'message' => 'Số mới phải lớn hơn hoặc bằng số cũ'], 400);
        return;
    }
    if (abs($usage_amount - ($new_reading - $old_reading)) > 0.01) {
        responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng phải bằng số mới trừ số cũ'], 400);
        return;
    }

    try {
        // Kiểm tra bản ghi tồn tại
        $stmt = $pdo->prepare("
            SELECT u.room_id, u.contract_id, u.service_id, u.month, s.name
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.id = ? AND u.deleted_at IS NULL
        ");
        $stmt->execute([$usage_id]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usage) {
            responseJson(['status' => 'error', 'message' => 'Bản ghi không tồn tại'], 404);
            return;
        }
        $existing_room_id = $usage['room_id'];
        $service_id = $usage['service_id'];
        $existing_month = $usage['month'];
        $service_name = $usage['name'];

        // Kiểm tra room_id và month khớp với bản ghi hiện tại
        if ($room_id !== $existing_room_id || $month !== $existing_month) {
            responseJson(['status' => 'error', 'message' => 'Không thể thay đổi room_id hoặc month của bản ghi'], 400);
            return;
        }

        // Lấy hợp đồng active hiện tại
        $stmt = $pdo->prepare("
            SELECT id
            FROM contracts
            WHERE room_id = ? 
            AND status = 'active'
            AND deleted_at IS NULL
            ORDER BY start_date DESC
            LIMIT 1
        ");
        $stmt->execute([$room_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy hợp đồng active cho phòng này'], 400);
            return;
        }
        $contract_id = $contract['id'];

        // Kiểm tra quyền owner/employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM rooms r
                JOIN branches b ON r.branch_id = b.id
                WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$room_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật bản ghi này'], 403);
                return;
            }
        }

        // Kiểm tra chỉ số trước đó
        $stmt = $pdo->prepare("
            SELECT new_reading 
            FROM utility_usage 
            WHERE room_id = ? 
            AND service_id = ? 
            AND month < ? 
            AND deleted_at IS NULL 
            ORDER BY recorded_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$room_id, $service_id, $month]);
        $previous_reading = $stmt->fetchColumn();
        if ($previous_reading !== false && $old_reading < $previous_reading) {
            responseJson(['status' => 'error', 'message' => "Số cũ ($old_reading) phải lớn hơn hoặc bằng số mới trước đó ($previous_reading)"], 400);
            return;
        }

        $pdo->beginTransaction();

        // Cập nhật bản ghi
        $stmt = $pdo->prepare("
            UPDATE utility_usage
            SET contract_id = ?, usage_amount = ?, old_reading = ?, new_reading = ?, recorded_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$contract_id, $usage_amount, $old_reading, $new_reading, $record_date, $usage_id]);

        $pdo->commit();

        // Gửi thông báo
        createNotification(
            $pdo,
            $user_id,
            "Đã cập nhật số {$service_name} (Số cũ: $old_reading, Số mới: $new_reading, Dùng: $usage_amount) cho phòng $room_id, hợp đồng $contract_id, tháng $month."
        );

        responseJson([
            'status' => 'success',
            'message' => 'Cập nhật số điện, nước thành công',
            'data' => [
                'id' => $usage_id,
                'room_id' => $room_id,
                'contract_id' => $contract_id,
                'service_id' => $service_id,
                'month' => $month,
                'usage_amount' => $usage_amount,
                'old_reading' => $old_reading,
                'new_reading' => $new_reading,
                'record_date' => $record_date,
                'service_name' => $service_name
            ]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi cập nhật số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Thêm hàng loạt bản ghi (POST /utility_usage/bulk)
function createBulkUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền nhập số điện, nước'], 403);
        return;
    }

    // Nhận dữ liệu đầu vào
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['utilities']) || !is_array($input['utilities']) || empty($input['utilities'])) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu đầu vào phải là mảng utilities không rỗng'], 400);
        return;
    }

    $utilities = $input['utilities'];
    $valid_entries = [];
    $errors = [];
    $created = [];

    try {
        $pdo->beginTransaction();

        // Tải danh sách phòng, dịch vụ
        $room_ids = array_unique(array_column($utilities, 'room_id'));
        $service_ids = array_unique(array_column($utilities, 'service_id'));
        $branch_ids = array_unique(array_column($utilities, 'branch_id'));

        // Kiểm tra phòng tồn tại
        $stmt = $pdo->prepare("SELECT id, branch_id FROM rooms WHERE id IN (" . implode(',', array_fill(0, count($room_ids), '?')) . ") AND deleted_at IS NULL");
        $stmt->execute($room_ids);
        $room_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $valid_rooms = array_column($room_data, 'id');
        $room_branch_map = array_column($room_data, 'branch_id', 'id');

        // Kiểm tra dịch vụ tồn tại
        $stmt = $pdo->prepare("SELECT id, name, type, branch_id FROM services WHERE id IN (" . implode(',', array_fill(0, count($service_ids), '?')) . ") AND deleted_at IS NULL");
        $stmt->execute($service_ids);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $valid_services = array_column($services, 'id');
        $service_map = array_column($services, null, 'id');

        // Tải hợp đồng active cho các phòng
        $stmt = $pdo->prepare("
            SELECT id, room_id, branch_id
            FROM contracts
            WHERE room_id IN (" . implode(',', array_fill(0, count($room_ids), '?')) . ")
            AND status = 'active'
            AND deleted_at IS NULL
        ");
        $stmt->execute($room_ids);
        $contract_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $contract_map = array_column($contract_data, null, 'room_id');

        // Kiểm tra quyền truy cập
        $allowed_room_ids = [];
        if ($role === 'admin') {
            $allowed_room_ids = $valid_rooms;
        } elseif ($role === 'owner') {
            $stmt = $pdo->prepare("
                SELECT r.id 
                FROM rooms r
                JOIN branches b ON r.branch_id = b.id
                WHERE r.id IN (" . implode(',', array_fill(0, count($room_ids), '?')) . ")
                AND b.owner_id = ?
                AND r.deleted_at IS NULL
                AND b.deleted_at IS NULL
            ");
            $stmt->execute(array_merge($room_ids, [$user_id]));
            $allowed_room_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        } elseif ($role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT r.id 
                FROM rooms r
                JOIN branches b ON r.branch_id = b.id
                JOIN employee_assignments ea ON ea.branch_id = b.id
                WHERE r.id IN (" . implode(',', array_fill(0, count($room_ids), '?')) . ")
                AND ea.employee_id = ?
                AND r.deleted_at IS NULL
                AND b.deleted_at IS NULL
            ");
            $stmt->execute(array_merge($room_ids, [$user_id]));
            $allowed_room_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        }

        // Validate từng bản ghi
        foreach ($utilities as $index => $entry) {
            validateRequiredFields($entry, ['room_id', 'service_id', 'month', 'old_reading', 'new_reading', 'record_date']);
            $data = sanitizeInput($entry);
            $room_id = (int)$data['room_id'];
            $service_id = (int)$data['service_id'];
            $branch_id = (int)$data['branch_id'];
            $month = $data['month'];
            $old_reading = (float)$data['old_reading'];
            $new_reading = (float)$data['new_reading'];
            $record_date = $data['record_date'];
            $usage_amount = $new_reading - $old_reading;

            // Kiểm tra định dạng và giá trị
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Định dạng tháng không hợp lệ (YYYY-MM)";
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $record_date)) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Định dạng ngày ghi nhận không hợp lệ (YYYY-MM-DD)";
                continue;
            }
            if ($usage_amount < 0 || $old_reading < 0 || $new_reading < 0) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Giá trị không được âm";
                continue;
            }
            if ($new_reading < $old_reading) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Số mới phải lớn hơn hoặc bằng số cũ";
                continue;
            }
            if (abs($usage_amount - ($new_reading - $old_reading)) > 0.01) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Số lượng sử dụng phải bằng số mới trừ số cũ";
                continue;
            }
            if (!in_array($room_id, $valid_rooms)) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Phòng ID $room_id không tồn tại";
                continue;
            }
            if (!in_array($service_id, $valid_services)) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Dịch vụ ID $service_id không tồn tại";
                continue;
            }
            if (!in_array($service_map[$service_id]['type'], ['electricity', 'water', 'other'])) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Dịch vụ phải là điện, nước hoặc khác";
                continue;
            }
            if (!in_array($room_id, $allowed_room_ids)) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Không có quyền nhập liệu cho phòng ID $room_id";
                continue;
            }
            if ($room_branch_map[$room_id] != $branch_id) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Phòng ID $room_id không thuộc chi nhánh ID $branch_id";
                continue;
            }
            if ($service_map[$service_id]['branch_id'] != $branch_id) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Dịch vụ ID $service_id không thuộc chi nhánh ID $branch_id";
                continue;
            }

            // Kiểm tra hợp đồng active
            if (!isset($contract_map[$room_id])) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Không tìm thấy hợp đồng active cho phòng ID $room_id";
                continue;
            }
            $contract = $contract_map[$room_id];
            $contract_id = $contract['id'];

            if ($contract['branch_id'] != $branch_id) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Hợp đồng ID $contract_id không thuộc chi nhánh ID $branch_id";
                continue;
            }

            // Kiểm tra chỉ số trước đó
            $stmt = $pdo->prepare("
                SELECT new_reading 
                FROM utility_usage 
                WHERE room_id = ? 
                AND service_id = ? 
                AND month < ? 
                AND deleted_at IS NULL 
                ORDER BY recorded_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$room_id, $service_id, $month]);
            $previous_reading = $stmt->fetchColumn();
            if ($previous_reading !== false && $old_reading < $previous_reading) {
                $errors[] = "Bản ghi " . ($index + 1) . ": Số cũ ($old_reading) phải lớn hơn hoặc bằng số mới trước đó ($previous_reading)";
                continue;
            }

            $valid_entries[] = [
                'room_id' => $room_id,
                'contract_id' => $contract_id,
                'service_id' => $service_id,
                'month' => $month,
                'usage_amount' => $usage_amount,
                'old_reading' => $old_reading,
                'new_reading' => $new_reading,
                'record_date' => $record_date,
                'service_name' => $service_map[$service_id]['name']
            ];
        }

        if (!empty($errors)) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
            responseJson(['status' => 'error', 'message' => implode('; ', $errors)], 400);
            return;
        }

        // Xử lý bản ghi tồn tại
        $existing_keys = [];
        if (!empty($valid_entries)) {
            $placeholders = implode(',', array_fill(0, count($valid_entries), '(?,?,?,?)'));
            $stmt = $pdo->prepare("
                SELECT room_id, contract_id, service_id, month, id
                FROM utility_usage
                WHERE (room_id, contract_id, service_id, month) IN ($placeholders)
                AND deleted_at IS NULL
            ");
            $params = [];
            foreach ($valid_entries as $entry) {
                $params[] = $entry['room_id'];
                $params[] = $entry['contract_id'];
                $params[] = $entry['service_id'];
                $params[] = $entry['month'];
            }
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = "{$row['room_id']}-{$row['contract_id']}-{$row['service_id']}-{$row['month']}";
                $existing_keys[$key] = $row['id'];
            }
        }

        // Cập nhật hoặc tạo mới
        foreach ($valid_entries as $index => $entry) {
            $key = "{$entry['room_id']}-{$entry['contract_id']}-{$entry['service_id']}-{$entry['month']}";
            if (isset($existing_keys[$key])) {
                // Cập nhật bản ghi hiện có
                $stmt = $pdo->prepare("
                    UPDATE utility_usage
                    SET usage_amount = ?, old_reading = ?, new_reading = ?, recorded_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$entry['usage_amount'], $entry['old_reading'], $entry['new_reading'], $entry['record_date'], $existing_keys[$key]]);
                $usage_id = $existing_keys[$key];
                $action = 'cập nhật';
            } else {
                // Tạo mới bản ghi
                $stmt = $pdo->prepare("
                    INSERT INTO utility_usage (room_id, contract_id, service_id, month, usage_amount, old_reading, new_reading, recorded_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $entry['room_id'],
                    $entry['contract_id'],
                    $entry['service_id'],
                    $entry['month'],
                    $entry['usage_amount'],
                    $entry['old_reading'],
                    $entry['new_reading'],
                    $entry['record_date']
                ]);
                $usage_id = $pdo->lastInsertId();
                $action = 'nhập';
            }

            // Lưu thông tin bản ghi đã xử lý
            $created[] = [
                'id' => $usage_id,
                'room_id' => $entry['room_id'],
                'contract_id' => $entry['contract_id'],
                'service_id' => $entry['service_id'],
                'month' => $entry['month'],
                'usage_amount' => $entry['usage_amount'],
                'old_reading' => $entry['old_reading'],
                'new_reading' => $entry['new_reading'],
                'record_date' => $entry['record_date'],
                'service_name' => $entry['service_name']
            ];

            // Gửi thông báo
            createNotification(
                $pdo,
                $user_id,
                "Đã $action số {$entry['service_name']} (Số cũ: {$entry['old_reading']}, Số mới: {$entry['new_reading']}, Dùng: {$entry['usage_amount']}) cho phòng {$entry['room_id']}, hợp đồng {$entry['contract_id']}, tháng {$entry['month']}."
            );
        }

        $pdo->commit();

        responseJson([
            'status' => 'success',
            'message' => 'Nhập hàng loạt số điện, nước thành công',
            'data' => [
                'count' => count($created),
                'created' => $created
            ]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi nhập hàng loạt số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()], 500);
    }
}

// Các hàm khác giữ nguyên từ phiên bản trước
function getUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem số điện, nước'], 403);
        return;
    }

    $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    $contract_id = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : null;
    $month = isset($_GET['month']) ? $_GET['month'] : null;
    $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = ['u.deleted_at IS NULL'];
    $params = [];

    if ($room_id) {
        $conditions[] = 'u.room_id = :room_id';
        $params['room_id'] = $room_id;
    }

    if ($contract_id) {
        $conditions[] = 'u.contract_id = :contract_id';
        $params['contract_id'] = $contract_id;
    }

    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $conditions[] = 'u.month = :month';
        $params['month'] = $month;
    }

    if ($branch_id) {
        $conditions[] = 'r.branch_id = :branch_id';
        $params['branch_id'] = $branch_id;
    }

    if ($role === 'owner' || $role === 'employee') {
        $conditions[] = 'r.branch_id IN (
            SELECT id FROM branches WHERE owner_id = :owner_id OR id IN (
                SELECT branch_id FROM employee_assignments WHERE employee_id = :employee_id
            )
        )';
        $params['owner_id'] = $user_id;
        $params['employee_id'] = $user_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT u.id, u.room_id, u.contract_id, u.service_id, u.month, u.usage_amount, u.old_reading, u.new_reading, u.recorded_at,
               s.name AS service_name, r.name AS room_name, b.name AS branch_name, b.id AS branch_id
        FROM utility_usage u
        JOIN services s ON u.service_id = s.id
        JOIN rooms r ON u.room_id = r.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY u.recorded_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        $count_query = "
            SELECT COUNT(*) FROM utility_usage u
            JOIN rooms r ON u.room_id = r.id
            $where_clause
        ";
        $count_stmt = $pdo->prepare($count_query);
        $count_params = array_filter($params, fn($key) => !in_array($key, ['limit', 'offset']), ARRAY_FILTER_USE_KEY);
        $count_stmt->execute($count_params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $usages,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function deleteUtilityUsage($usage_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa số điện, nước'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT u.room_id, u.contract_id, u.service_id, u.month, s.name
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.id = ? AND u.deleted_at IS NULL
        ");
        $stmt->execute([$usage_id]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usage) {
            responseJson(['status' => 'error', 'message' => 'Bản ghi không tồn tại'], 404);
            return;
        }
        $room_id = $usage['room_id'];
        $contract_id = $usage['contract_id'];
        $service_name = $usage['name'];
        $month = $usage['month'];

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM rooms r
                JOIN branches b ON r.branch_id = b.id
                WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$room_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa bản ghi này'], 403);
                return;
            }
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE utility_usage
            SET deleted_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$usage_id]);

        $pdo->commit();

        createNotification(
            $pdo,
            $user_id,
            "Đã xóa số {$service_name} cho phòng $room_id, hợp đồng $contract_id, tháng $month."
        );

        responseJson([
            'status' => 'success',
            'message' => 'Xóa số điện, nước thành công',
            'data' => ['id' => $usage_id]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi xóa số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function getLatestUtilityReading() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem số điện, nước'], 403);
        return;
    }

    $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    $service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : null;
    $contract_id = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : null;
    $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    if (!$room_id || !$service_id) {
        responseJson(['status' => 'error', 'message' => 'room_id và service_id là bắt buộc'], 400);
        return;
    }

    $conditions = ['u.deleted_at IS NULL', 'u.room_id = :room_id', 'u.service_id = :service_id'];
    $params = ['room_id' => $room_id, 'service_id' => $service_id];

    if ($contract_id) {
        $conditions[] = 'u.contract_id = :contract_id';
        $params['contract_id'] = $contract_id;
    }

    if ($branch_id) {
        $conditions[] = 'r.branch_id = :branch_id';
        $params['branch_id'] = $branch_id;
    }

    if ($role === 'owner' || $role === 'employee') {
        $conditions[] = 'r.branch_id IN (
            SELECT id FROM branches WHERE owner_id = :owner_id OR id IN (
                SELECT branch_id FROM employee_assignments WHERE employee_id = :employee_id
            )
        )';
        $params['owner_id'] = $user_id;
        $params['employee_id'] = $user_id;
    }

    $where_clause = 'WHERE ' . implode(' AND ', $conditions);

    $query = "
        SELECT u.new_reading, u.recorded_at, u.contract_id
        FROM utility_usage u
        JOIN rooms r ON u.room_id = r.id
        $where_clause
        ORDER BY u.recorded_at DESC
        LIMIT 1
    ";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $latest ? [
                'new_reading' => $latest['new_reading'],
                'recorded_at' => $latest['recorded_at'],
                'contract_id' => $latest['contract_id']
            ] : ['new_reading' => 0]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy new_reading gần nhất: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function getUtilityUsageSummary() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem tổng hợp số điện, nước'], 403);
        return;
    }

    $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    $contract_id = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : null;
    $service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : null;
    $month = isset($_GET['month']) ? $_GET['month'] : null;
    $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    $conditions = ['u.deleted_at IS NULL'];
    $params = [];

    if ($room_id) {
        $conditions[] = 'u.room_id = :room_id';
        $params['room_id'] = $room_id;
    }

    if ($contract_id) {
        $conditions[] = 'u.contract_id = :contract_id';
        $params['contract_id'] = $contract_id;
    }

    if ($service_id) {
        $conditions[] = 'u.service_id = :service_id';
        $params['service_id'] = $service_id;
    }

    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $conditions[] = 'u.month = :month';
        $params['month'] = $month;
    }

    if ($branch_id) {
        $conditions[] = 'r.branch_id = :branch_id';
        $params['branch_id'] = $branch_id;
    }

    if ($role === 'owner' || $role === 'employee') {
        $conditions[] = 'r.branch_id IN (
            SELECT id FROM branches WHERE owner_id = :owner_id OR id IN (
                SELECT branch_id FROM employee_assignments WHERE employee_id = :employee_id
            )
        )';
        $params['owner_id'] = $user_id;
        $params['employee_id'] = $user_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            u.month,
            u.service_id,
            u.contract_id,
            s.name AS service_name,
            SUM(u.usage_amount) AS total_usage,
            COUNT(u.id) AS record_count
        FROM utility_usage u
        JOIN services s ON u.service_id = s.id
        JOIN rooms r ON u.room_id = r.id
        $where_clause
        GROUP BY u.month, u.service_id, u.contract_id, s.name
    ";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $summary
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy tổng hợp số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// // Lấy chi tiết hoặc danh sách số điện, nước (GET /utility_usage/{id} hoặc GET /utility_usage?room_id=...&service_id=...)
// function getUtilityUsageById($usage_id = null) {
//     $pdo = getDB();
//     $user = verifyJWT();
//     $user_id = $user['user_id'];
//     $role = $user['role'];

//     if (!in_array($role, ['admin', 'owner', 'employee'])) {
//         responseJson(['status' => 'error', 'message' => 'Không có quyền xem số điện, nước'], 403);
//         return;
//     }

//     // Nếu có usage_id, trả về chi tiết 1 bản ghi
//     if ($usage_id !== null) {
//         try {
//             $stmt = $pdo->prepare("
//                 SELECT u.id, u.room_id, u.contract_id, u.service_id, u.month, u.usage_amount, u.old_reading, u.new_reading, u.recorded_at,
//                        s.name AS service_name, r.name AS room_name, b.name AS branch_name, b.id AS branch_id
//                 FROM utility_usage u
//                 JOIN services s ON u.service_id = s.id
//                 JOIN rooms r ON u.room_id = r.id
//                 JOIN branches b ON r.branch_id = b.id
//                 WHERE u.id = ? AND u.deleted_at IS NULL
//             ");
//             $stmt->execute([$usage_id]);
//             $usage = $stmt->fetch(PDO::FETCH_ASSOC);
//             if (!$usage) {
//                 responseJson(['status' => 'error', 'message' => 'Bản ghi không tồn tại'], 404);
//                 return;
//             }
//             // Kiểm tra quyền owner/employee chỉ xem được phòng thuộc chi nhánh mình quản lý
//             if ($role === 'owner' || $role === 'employee') {
//                 $stmt = $pdo->prepare("
//                     SELECT 1 FROM rooms r
//                     JOIN branches b ON r.branch_id = b.id
//                     WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
//                         SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
//                     ))
//                 ");
//                 $stmt->execute([$usage['room_id'], $user_id, $user_id]);
//                 if (!$stmt->fetch()) {
//                     responseJson(['status' => 'error', 'message' => 'Không có quyền xem bản ghi này'], 403);
//                     return;
//                 }
//             }
//             responseJson([
//                 'status' => 'success',
//                 'data' => $usage
//             ]);
//         } catch (PDOException $e) {
//             error_log("Lỗi lấy chi tiết số điện, nước: " . $e->getMessage());
//             responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
//         }
//         return;
//     }

//     // Nếu không có usage_id, trả về danh sách có phân trang
//     $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
//     $contract_id = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : null;
//     $service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : null;
//     $month = isset($_GET['month']) ? $_GET['month'] : null;
//     $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
//     $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
//     $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
//     $offset = ($page - 1) * $limit;

//     $conditions = ['u.deleted_at IS NULL'];
//     $params = [];
//     if ($room_id) {
//         $conditions[] = 'u.room_id = :room_id';
//         $params['room_id'] = $room_id;
//     }
//     if ($contract_id) {
//         $conditions[] = 'u.contract_id = :contract_id';
//         $params['contract_id'] = $contract_id;
//     }
//     if ($service_id) {
//         $conditions[] = 'u.service_id = :service_id';
//         $params['service_id'] = $service_id;
//     }
//     if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
//         $conditions[] = 'u.month = :month';
//         $params['month'] = $month;
//     }
//     if ($branch_id) {
//         $conditions[] = 'r.branch_id = :branch_id';
//         $params['branch_id'] = $branch_id;
//     }
//     if ($role === 'owner' || $role === 'employee') {
//         $conditions[] = 'r.branch_id IN (
//             SELECT id FROM branches WHERE owner_id = :owner_id OR id IN (
//                 SELECT branch_id FROM employee_assignments WHERE employee_id = :employee_id
//             )
//         )';
//         $params['owner_id'] = $user_id;
//         $params['employee_id'] = $user_id;
//     }
//     $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
//     $query = "
//         SELECT u.id, u.room_id, u.contract_id, u.service_id, u.month, u.usage_amount, u.old_reading, u.new_reading, u.recorded_at,
//                s.name AS service_name, r.name AS room_name, b.name AS branch_name, b.id AS branch_id
//         FROM utility_usage u
//         JOIN services s ON u.service_id = s.id
//         JOIN rooms r ON u.room_id = r.id
//         JOIN branches b ON r.branch_id = b.id
//         $where_clause
//         ORDER BY u.recorded_at DESC
//         LIMIT $limit OFFSET $offset
//     ";
//     try {
//         $count_query = "
//             SELECT COUNT(*) FROM utility_usage u
//             JOIN rooms r ON u.room_id = r.id
//             $where_clause
//         ";
//         $count_stmt = $pdo->prepare($count_query);
//         $count_params = array_filter($params, fn($key) => !in_array($key, ['limit', 'offset']), ARRAY_FILTER_USE_KEY);
//         $count_stmt->execute($count_params);
//         $total_records = $count_stmt->fetchColumn();
//         $total_pages = ceil($total_records / $limit);

//         $stmt = $pdo->prepare($query);
//         $stmt->execute($params);
//         $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

//         responseJson([
//             'status' => 'success',
//             'data' => $usages,
//             'pagination' => [
//                 'current_page' => $page,
//                 'limit' => $limit,
//                 'total_records' => $total_records,
//                 'total_pages' => $total_pages
//             ]
//         ]);
//     } catch (PDOException $e) {
//         error_log("Lỗi lấy danh sách số điện, nước: " . $e->getMessage());
//         responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
//     }
// }
?>