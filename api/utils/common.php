<?php
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';

function validateRequiredFields($input, $fields) {
    foreach ($fields as $field) {
        // Kiểm tra trường có tồn tại và không phải null
        if (!isset($input[$field]) || $input[$field] === null) {
            responseJson(['status' => 'error', 'message' => "Thiếu trường $field"], 400);
        }
    }
}

function validateEmail($email) {
    $email = filter_var($email, FILTER_VALIDATE_EMAIL);
    if (!$email) {
        responseJson(['status' => 'error', 'message' => 'Email không hợp lệ'], 400);
    }
    return $email;
}

function checkUserExists($pdo, $email, $username = null, $excludeId = null) {
    $query = "SELECT id FROM users WHERE (email = ? OR username = ?)";
    $params = [$email, $username ?: $email];
    if ($excludeId) {
        $query .= " AND id != ?";
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Email hoặc username đã tồn tại'], 409);
    }
}

function createNotification($pdo, $userId, $message) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $stmt->execute([$userId, $message]);
}

function sanitizeUserInput($input) {
    return [
        'username' => sanitizeInput($input['username'] ?? ''),
        'email' => sanitizeInput($input['email'] ?? ''),
        'name' => sanitizeInput($input['name'] ?? ''),
        'phone' => sanitizeInput($input['phone'] ?? ''),
        'role' => $input['role'] ?? 'customer'
    ];
}

function getResourceIdFromUri($pattern) {
    $uri = $_SERVER['REQUEST_URI'];
    preg_match($pattern, $uri, $matches);
    $id = $matches[1] ?? null;
    if (!$id || !filter_var($id, FILTER_VALIDATE_INT)) {
        responseJson(['status' => 'error', 'message' => 'ID không hợp lệ'], 400);
    }
    return $id;
}

function checkResourceExists($pdo, $table, $id, $column = 'id') {
    $stmt = $pdo->prepare("SELECT $column FROM $table WHERE $column = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => "Không tìm thấy $table với ID $id"], 404);
    }
}
?>