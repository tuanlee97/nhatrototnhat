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

$dbConfig = $_SESSION['db_config'];
$errors = [];

// Xử lý form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validate
        if (empty($username) || empty($email) || empty($password)) {
            throw new Exception('Vui lòng nhập đầy đủ thông tin.');
        }

        if (strlen($username) < 5) {
            throw new Exception('Tên người dùng phải có ít nhất 5 ký tự.');
        }
           
        if (strlen($password) < 6) {
            throw new Exception('Mật khẩu phải có ít nhất 6 ký tự.');
        }

        if ($password !== $confirmPassword) {
            throw new Exception('Mật khẩu xác nhận không khớp.');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email không hợp lệ.');
        }

        // Kết nối database
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']}",
            $dbConfig['user'],
            $dbConfig['pass']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Lưu admin
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'admin','active')");
        $stmt->execute([$username, $email, $hashedPassword]);

        logError('Tạo tài khoản admin thành công: ' . $username);

        // Chuyển hướng đến complete.php
        header('Location: ' . getBasePath() . 'install/complete.php');
        ob_end_flush();
        exit;
    } catch (Exception $e) {
        logError('Lỗi bước cài đặt 3: ' . $e->getMessage());
        $errors[] = 'Lỗi: ' . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cài đặt hệ thống - Bước 3</title>
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
            <h1 class="text-xl font-semibold text-gray-900 mb-4 text-center">Cài đặt hệ thống - Bước 3</h1>
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-300 text-red-700 text-sm p-3 rounded-md mb-4">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo $error; ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="space-y-4" id="installForm" onsubmit="return validateForm(event)">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700">Tên người dùng <small class="text-gray-500 text-xs">(ít nhất 5 ký tự)</small></label>
                    <input 
                        type="text" 
                        name="username" 
                        id="username" 
                        required 
                        class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    >
                    <p id="username_error" class="text-red-700 text-xs mt-1 hidden">Vui lòng nhập tên người dùng.</p>
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input 
                        type="email" 
                        name="email" 
                        id="email" 
                        required 
                        class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    >
                    <p id="email_error" class="text-red-700 text-xs mt-1 hidden">Vui lòng nhập email hợp lệ.</p>
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Mật khẩu <small class="text-gray-500 text-xs">(ít nhất 6 ký tự)</small></label>
                  
                    <input 
                        type="password" 
                        name="password" 
                        id="password" 
                        required 
                        class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    >
                    <p id="password_error" class="text-red-700 text-xs mt-1 hidden">Vui lòng nhập mật khẩu.</p>
                </div>
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700">Xác nhận mật khẩu</label>
                    <input 
                        type="password" 
                        name="confirm_password" 
                        id="confirm_password" 
                        required 
                        class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md text-gray-900 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
                    >
                    <p id="confirm_password_error" class="text-red-700 text-xs mt-1 hidden">Mật khẩu xác nhận không khớp.</p>
                </div>
                <div>
                    <button 
                        type="submit" 
                        class="w-full py-2 px-4 bg-gray-800 border border-gray-700 rounded-md text-gray-200 text-sm font-medium hover:bg-gray-700 hover:border-gray-600 focus:outline-none focus:ring-1 focus:ring-gray-500 focus:ring-offset-1 focus:ring-offset-white transition-all"
                    >
                        Tạo Admin
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

            // Validate username
            const username = document.getElementById('username').value.trim();
            if (!username) {
                document.getElementById('username_error').classList.remove('hidden');
                isValid = false;
            }
             if (!username || username.length < 5) {
                document.getElementById('username_error').textContent = 'Tên người dùng phải có ít nhất 5 ký tự.';
                document.getElementById('username_error').classList.remove('hidden');
                isValid = false;
            }
            // Validate email
            const email = document.getElementById('email').value.trim();
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                document.getElementById('email_error').classList.remove('hidden');
                isValid = false;
            }

            // Validate password
            const password = document.getElementById('password').value.trim();
            if (!password) {
                document.getElementById('password_error').classList.remove('hidden');
                isValid = false;
            }
            if (!password || password.length < 6) {
                document.getElementById('password_error').textContent = 'Mật khẩu phải có ít nhất 6 ký tự.';
                document.getElementById('password_error').classList.remove('hidden');
                isValid = false;
            }
            // Validate confirm password
            const confirmPassword = document.getElementById('confirm_password').value.trim();
            if (confirmPassword !== password) {
                document.getElementById('confirm_password_error').classList.remove('hidden');
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