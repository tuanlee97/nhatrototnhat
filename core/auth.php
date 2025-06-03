<?php



function generateJWT($userId, $role) {
    $app = require __DIR__ . '/../config/app.php';
    $secret = $app['SECRET_KEY'];
    $exp = time() + 3600;

    $payload = base64_encode(json_encode([
        'user_id' => $userId,
        'role' => $role,
        'exp' => $exp
    ]));
    $signature = hash_hmac('sha256', $payload, $secret);
    return [
        'exp' => $exp,
        'token' => "$payload.$signature"
    ];
}

function verifyJWT() {
    $headers = getallheaders();
    $app = require __DIR__ . '/../config/app.php';
    $authHeader = $headers['Authorization'] ?? '';
    if (!preg_match('/Bearer (.+)/', $authHeader, $matches)) {
        responseJson(['status' => 'error', 'message' => 'Thiếu header Authorization'], 401);
    }

    $token = $matches[1];
    list($payload, $signature) = explode('.', $token);
    $secret = $app['SECRET_KEY'];
    $expectedSignature = hash_hmac('sha256', $payload, $secret);

    if ($signature !== $expectedSignature) {
        responseJson(['status' => 'error', 'message' => 'Token không hợp lệ'], 401);
    }

    $decoded = json_decode(base64_decode($payload), true);
    if ($decoded['exp'] < time()) {
        responseJson(['status' => 'error', 'message' => 'Token đã hết hạn'], 401);
    }

    // Kiểm tra token trong blacklist
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM token_blacklist WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    if ($stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Token đã bị hủy'], 401);
    }

    return $decoded;
}
function authMiddleware($requiredRoles) {
    $user = verifyJWT();
    $allowedRoles = explode(',', $requiredRoles);
    if (!in_array($user['role'], $allowedRoles)) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
    }
}

function nonAuthMiddleware() {
    $headers = getallheaders();
    if (isset($headers['Authorization']) && preg_match('/Bearer (.+)/', $headers['Authorization'])) {
        responseJson(['status' => 'error', 'message' => 'API này không yêu cầu xác thực'], 403);
    }
}

function verifyGoogleToken($token) {
    $app = require __DIR__ . '/../config/app.php';
    $clientId = $app['YOUR_GOOGLE_CLIENT_ID'];
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=$token";
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    if (!$data || isset($data['error']) || $data['aud'] !== $clientId) {
        return false;
    }

    return $data; // Trả về thông tin user từ Google
}
?>