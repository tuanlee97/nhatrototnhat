<?php
ini_set('display_errors', 1);
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

// Tạo file installed.php
$errors = [];
try {
    $result = file_put_contents($installedFile, '<?php // Installed on ' . date('Y-m-d H:i:s') . ' ?>');
    if ($result === false) {
        throw new Exception('Không thể tạo file config/installed.php.');
    }

    logError('Cài đặt hệ thống hoàn tất.');

    // Xóa session
    session_destroy();
} catch (Exception $e) {
    logError('Lỗi bước cài đặt 4: ' . $e->getMessage());
    $errors[] = 'Lỗi: ' . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cài đặt hệ thống - Hoàn tất</title>
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
            <h1 class="text-xl font-semibold text-gray-900 mb-4 text-center">Cài đặt hệ thống - Hoàn tất</h1>
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-300 text-red-700 text-sm p-3 rounded-md mb-4">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo $error; ?></p>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bg-green-100 border border-green-300 text-green-700 text-sm p-3 rounded-md mb-4">
                    <p>Cài đặt hệ thống thành công!</p>
                </div>
                <p class="text-sm text-gray-600 mb-4">Hệ thống đã sẵn sàng. Nhấn nút dưới để tiếp tục.</p>
                <a 
                    href="<?php echo getBasePath(); ?>" 
                    class="w-full py-2 px-4 bg-gray-800 border border-gray-700 rounded-md text-gray-200 text-sm font-medium hover:bg-gray-700 hover:border-gray-600 focus:outline-none focus:ring-1 focus:ring-gray-500 focus:ring-offset-1 focus:ring-offset-white transition-all text-center block"
                >
                    Vào trang chính
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php ob_end_flush(); ?>