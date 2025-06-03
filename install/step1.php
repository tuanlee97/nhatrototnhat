<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '../logs/php_errors.log');

ob_start();

require_once '../core/helpers.php';

// Kiểm tra nếu đã cài đặt
$installedFile = '../config/installed.php';
if (file_exists($installedFile)) {
    header('Location: ' . getBasePath());
    ob_end_flush();
    exit;
}

// Kiểm tra session
session_start();
if (!isset($_SESSION['db_config'])) {
    header('Location: ' . getBasePath() . 'install/index.php');
    ob_end_flush();
    exit;
}

$dbConfig = $_SESSION['db_config'];
$errors = [];

// Xử lý form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']}",
            $dbConfig['user'],
            $dbConfig['pass']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Đọc file schema.sql
        $schemaFile = '../migrations/schema.sql';
        if (!file_exists($schemaFile)) {
            throw new Exception('File migrations/schema.sql không tồn tại.');
        }

        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new Exception('Không thể đọc file migrations/schema.sql.');
        }

        // Thực thi các câu lệnh SQL
        $pdo->exec($sql);

        // Lưu cấu hình database
        $dbConfigContent = "<?php\nreturn " . var_export([
            'host' => $dbConfig['host'],
            'name' => $dbConfig['name'],
            'user' => $dbConfig['user'],
            'pass' => $dbConfig['pass'],
            'port' => $dbConfig['port'] ?? '3306',
        ], true) . ";\n?>";
        $result = file_put_contents('../config/database.php', $dbConfigContent);
        if ($result === false) {
            throw new Exception('Không thể tạo file config/database.php.');
        }

        logError('Tạo database và bảng thành công: ' . $dbConfig['name']);

        // Chuyển hướng đến step2.php
        header('Location: ' . getBasePath() . 'install/step2.php');
        ob_end_flush();
        exit;
    } catch (Exception $e) {
        logError('Lỗi bước cài đặt 1: ' . $e->getMessage());
        $errors[] = 'Lỗi: ' . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cài đặt hệ thống - Bước 1</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        .transition-all {
            transition: all 0.2s ease-in-out;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen px-4">
    <div class="w-full max-w-md">
        <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
            <h1 class="text-xl font-semibold text-gray-900 mb-4 text-center">Cài đặt hệ thống - Bước 1</h1>
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-300 text-red-700 text-sm p-3 rounded-md mb-4">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo $error; ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="space-y-4" id="installForm">
                <p class="text-sm text-gray-600">Kết nối database và tạo cấu trúc bảng.</p>
                <div>
                    <button 
                        type="submit" 
                        class="w-full py-2 px-4 bg-gray-800 border border-gray-700 rounded-md text-gray-200 text-sm font-medium hover:bg-gray-700 hover:border-gray-600 focus:outline-none focus:ring-1 focus:ring-gray-500 focus:ring-offset-1 focus:ring-offset-white transition-all"
                    >
                        Tạo Database
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
<?php ob_end_flush(); ?>