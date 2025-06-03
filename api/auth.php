<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function login() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['username', 'password']);

    // $email = validateEmail($input['email']);
    $username = $input['username'];
    $password = $input['password'];

    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT id, name, email, username, password, role, status, phone,bank_details,qr_code_url FROM users WHERE username = ? AND provider = 'email'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
       
        if (!$user || !password_verify($password, $user['password'])) {
            responseJson(['status' => 'error', 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng'], 401);
        }

        if ($user['status'] !== 'active') {
            responseJson(['status' => 'error', 'message' => 'Tài khoản chưa được kích hoạt'], 403);
        }
        $jwt = generateJWT($user['id'], $user['role']);
        $token = $jwt['token'];
        $user['exp'] = $jwt['exp'] ?? null;
        unset($user['password']); // Bỏ mật khẩu ra khỏi kết quả
        
        createNotification($pdo, $user['id'], "Chào mừng {$user['username']} đã đăng nhập!");
        responseJson(['status' => 'success', 'data' => ['token' => $token, 'user' => $user]]);
    } catch (Exception $e) {
        error_log('Lỗi đăng nhập: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function logout() {
    $headers = getallheaders();
    preg_match('/Bearer (.+)/', $headers['Authorization'], $matches);
    $token = $matches[1];

    $decoded = verifyJWT(); // Xác minh token trước khi hủy
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("INSERT INTO token_blacklist (token, expires_at) VALUES (?, FROM_UNIXTIME(?))");
        $stmt->execute([$token, $decoded['exp']]);
        responseJson(['status' => 'success', 'message' => 'Đăng xuất thành công']);
    } catch (Exception $e) {
        error_log('Lỗi đăng xuất: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>