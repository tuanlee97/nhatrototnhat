<?php
require_once  '../core/helpers.php';
require_once  '../core/auth.php';
require_once  '../core/database.php';

$user = authMiddleware('admin'); // Chỉ admin truy cập

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = getDB();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $output = "-- Backup Database: " . date('Y-m-d H:i:s') . "\n";
        $output .= "SET FOREIGN_KEY_CHECKS = 0;\n";

        foreach ($tables as $table) {
            $output .= "-- Table: $table\n";
            $createTable = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
            $output .= "$createTable;\n\n";

            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $output .= "INSERT INTO `$table` VALUES\n";
                $values = [];
                foreach ($rows as $row) {
                    $rowValues = array_map(function ($value) use ($pdo) {
                        return is_null($value) ? 'NULL' : $pdo->quote($value);
                    }, array_values($row));
                    $values[] = '(' . implode(',', $rowValues) . ')';
                }
                $output .= implode(",\n", $values) . ";\n\n";
            }
        }

        $output .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $filename = 'backup_' . date('Ymd_His') . '.sql';

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $output;
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo 'Lỗi sao lưu database: ' . htmlspecialchars($e->getMessage());
        logError('Lỗi sao lưu database: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sao Lưu Database</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="assets/styles.css" rel="stylesheet">
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
        <h1 class="text-2xl font-bold mb-6 text-center">Sao Lưu Database</h1>
        <p class="mb-4">Nhấn nút dưới đây để tải file sao lưu database (định dạng SQL).</p>
        <form method="POST">
            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 w-full">Tải File Sao Lưu</button>
        </form>
        <p class="mt-4 text-sm text-gray-600">Lưu ý: Chỉ quản trị viên (admin) có quyền sao lưu.</p>
    </div>
</body>
</html>
?>