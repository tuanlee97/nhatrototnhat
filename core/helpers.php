<?php
function getBasePath() {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    // Loại bỏ '/install' nếu có
    $baseDir = preg_replace('#/install$#', '', $scriptDir);
    $basePath = 'http://' . $_SERVER['HTTP_HOST'] . $baseDir . '/';
    error_log('getBasePath: ' . $basePath);
    return $basePath;
}

function logError($message) {
    $logFile = __DIR__ . '/../logs/api.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
}

function responseJson($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getClientIp() {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function checkRateLimit($ip) {
    $cacheFile = __DIR__ . '/../cache/rate_limit.json';
    $limit = 1000; // 100 request/giờ
    $window = 3600; // 1 giờ

    $data = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
    $currentTime = time();

    if (!isset($data[$ip]) || $data[$ip]['reset'] < $currentTime) {
        $data[$ip] = ['count' => 0, 'reset' => $currentTime + $window];
    }

    if ($data[$ip]['count'] >= $limit) {
        responseJson(['status' => 'error', 'message' => 'Vượt quá giới hạn yêu cầu. Vui lòng thử lại sau.'], 429);
    }

    $data[$ip]['count']++;
    file_put_contents($cacheFile, json_encode($data));
}
?>