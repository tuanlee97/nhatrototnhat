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

// Kiểm tra môi trường
$errors = [];
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    $errors[] = 'Yêu cầu PHP 8.0 hoặc cao hơn.';
}
if (!extension_loaded('pdo_mysql')) {
    $errors[] = 'Yêu cầu extension PDO MySQL.';
}
if (!is_writable('../config/')) {
    $errors[] = 'Thư mục config/ cần có quyền ghi.';
}
if (!is_writable('../logs/')) {
    $errors[] = 'Thư mục logs/ cần có quyền ghi.';
}

// Xử lý form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $dbHost = $_POST['db_host'] ?? '';
        $dbName = $_POST['db_name'] ?? '';
        $dbUser = $_POST['db_user'] ?? '';
        $dbPass = $_POST['db_pass'] ?? '';
        $dbPort = $_POST['db_port'] ?? '3306';
        // Lưu thông tin database vào session
        session_start();
        $_SESSION['db_config'] = [
            'host' => $dbHost,
            'port' => $dbPort,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass
        ];

        // Chuyển hướng đến step1.php
        header('Location: ' . getBasePath() . 'install/step1.php');
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
            <form method="POST" class="space-y-4" id="installForm" onsubmit="return validateForm(event)">
                <div>
                    <label for="db_host" class="block text-sm font-medium text-gray-700">Database Host</label>
                    <input 
                        type="text" 
                        name="db_host" 
                        id="db_host" 
                        value="localhost" 
                        required 
                        class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    >
                    <p id="db_host_error" class="text-red-700 text-xs mt-1 hidden">Vui lòng nhập Database Host.</p>
                </div>
                <div>
                    <label for="db_port" class="block text-sm font-medium text-gray-700">Database Port</label>
                    <input 
                        type="number" 
                        name="db_port" 
                        id="db_port" 
                        value="3306" 
                        required 
                        class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    >
                    <p id="db_port_error" class="text-red-700 text-xs mt-1 hidden">Vui lòng nhập Database Port.</p>
                </div>
                <div>
                    <label for="db_name" class="block text-sm font-medium text-gray-700">Database Name</label>
                    <input 
                        type="text" 
                        name="db_name" 
                        id="db_name" 
                        required 
                        class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    >
                    <p id="db_name_error" class="text-red-700 text-xs mt-1 hidden">Vui lòng nhập Database Name.</p>
                </div>
                <div>
                    <label for="db_user" class="block text-sm font-medium text-gray-700">Database User</label>
                    <input 
                        type="text" 
                        name="db_user" 
                        id="db_user" 
                        required 
                        class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    >
                    <p id="db_user_error" class="text-red-700 text-xs mt-1 hidden">Vui lòng nhập Database User.</p>
                </div>
                <div>
                    <label for="db_pass" class="block text-sm font-medium text-gray-700">Database Password</label>
                    <input 
                        type="password" 
                        name="db_pass" 
                        id="db_pass" 
                        class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    >
                </div>
                <div>
                    <button 
                        type="submit" 
                        class="w-full py-2 px-4 bg-gray-800 border border-gray-700 rounded-md text-gray-200 text-sm font-medium hover:bg-gray-700 hover:border-gray-600 focus:outline-none focus:ring-1 focus:ring-gray-500 focus:ring-offset-1 focus:ring-offset-white transition-all"
                    >
                        Tiếp tục
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function validateForm(event) {
            event.preventDefault();
            let isValid = true;

            // Reset error messages
            document.querySelectorAll('.text-red-700').forEach(el => el.classList.add('hidden'));

            // Validate db_host
            const dbHost = document.getElementById('db_host').value.trim();
            if (!dbHost) {
                document.getElementById('db_host_error').classList.remove('hidden');
                isValid = false;
            }

            // Validate db_name
            const dbName = document.getElementById('db_name').value.trim();
            if (!dbName) {
                document.getElementById('db_name_error').classList.remove('hidden');
                isValid = false;
            }

            // Validate db_user
            const dbUser = document.getElementById('db_user').value.trim();
            if (!dbUser) {
                document.getElementById('db_user_error').classList.remove('hidden');
                isValid = false;
            }
            // Validate db_port
            const dbPort = document.getElementById('db_port').value.trim();
            if (!dbPort || isNaN(dbPort)) {
                document.getElementById('db_port_error').classList.remove('hidden');
                isValid = false;
            }

            // If all validations pass, submit the form
            if (isValid) {
                document.getElementById('installForm').submit();
            }



            return isValid;
        }
    </script>
</body>
</html>
<?php ob_end_flush(); ?>