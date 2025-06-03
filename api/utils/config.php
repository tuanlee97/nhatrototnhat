<?php
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/common.php';

function getConfig() {
    $pdo = getDB();
    try {
        $stmt = $pdo->query("SELECT key, value FROM settings WHERE key LIKE 'base_url%'");
        $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        responseJson(['status' => 'success', 'data' => $configs]);
    } catch (Exception $e) {
        logError('Lỗi lấy cấu hình base URL: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}
?>