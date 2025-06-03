<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getSettings() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['key'])) {
        $conditions[] = "s.key = ?";
        $params[] = sanitizeInput($_GET['key']);
    }

    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "s.value LIKE ?";
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "SELECT s.* FROM settings s $whereClause";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM settings s $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $settings = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $settings,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}


function createSetting() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['key', 'value']);
    $user = verifyJWT();

    $key = sanitizeInput($input['key']);
    $value = sanitizeInput($input['value']);
    $description = !empty($input['description']) ? sanitizeInput($input['description']) : null;

    $pdo = getDB();
    try {
        if ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Chỉ admin có quyền tạo cài đặt'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Khóa cài đặt đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO settings (key, value, description)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$key, $value, $description]);

        $settingId = $pdo->lastInsertId();
        createNotification($pdo, $user['user_id'], "Cài đặt ID $settingId đã được tạo.");
        responseJson(['status' => 'success', 'data' => ['setting_id' => $settingId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo setting: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getSettingById() {
    $settingId = getResourceIdFromUri('#/settings/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        if ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Chỉ admin có quyền truy cập cài đặt'], 403);
        }

        $stmt = $pdo->prepare("SELECT id, key, value, description FROM settings WHERE id = ?");
        $stmt->execute([$settingId]);
        $setting = $stmt->fetch();

        if (!$setting) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy cài đặt'], 404);
        }
        responseJson(['status' => 'success', 'data' => $setting]);
    } catch (Exception $e) {
        logError('Lỗi lấy setting ID ' . $settingId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateSetting() {
    $settingId = getResourceIdFromUri('#/settings/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['key', 'value']);
    $user = verifyJWT();

    $key = sanitizeInput($input['key']);
    $value = sanitizeInput($input['value']);
    $description = !empty($input['description']) ? sanitizeInput($input['description']) : null;

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'settings', $settingId);
        if ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Chỉ admin có quyền chỉnh sửa cài đặt'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM settings WHERE key = ? AND id != ?");
        $stmt->execute([$key, $settingId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Khóa cài đặt đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            UPDATE settings SET key = ?, value = ?, description = ?
            WHERE id = ?
        ");
        $stmt->execute([$key, $value, $description, $settingId]);

        createNotification($pdo, $user['user_id'], "Cài đặt ID $settingId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật cài đặt thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật setting ID ' . $settingId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchSetting() {
    $settingId = getResourceIdFromUri('#/settings/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'settings', $settingId);
        if ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Chỉ admin có quyền chỉnh sửa cài đặt'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['key'])) {
            $key = sanitizeInput($input['key']);
            $stmt = $pdo->prepare("SELECT id FROM settings WHERE key = ? AND id != ?");
            $stmt->execute([$key, $settingId]);
            if ($stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Khóa cài đặt đã tồn tại'], 409);
            }
            $updates[] = "key = ?";
            $params[] = $key;
        }
        if (isset($input['value'])) {
            $updates[] = "value = ?";
            $params[] = sanitizeInput($input['value']);
        }
        if (isset($input['description'])) {
            $updates[] = "description = ?";
            $params[] = sanitizeInput($input['description']);
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE settings SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $settingId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Cài đặt ID $settingId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật cài đặt thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch setting ID ' . $settingId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteSetting() {
    $settingId = getResourceIdFromUri('#/settings/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'settings', $settingId);
        if ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Chỉ admin có quyền xóa cài đặt'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM settings WHERE id = ?");
        $stmt->execute([$settingId]);
        responseJson(['status' => 'success', 'message' => 'Xóa cài đặt thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa setting ID ' . $settingId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>