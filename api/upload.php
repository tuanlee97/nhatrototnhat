<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Xử lý tải lên mã QR (POST /upload-qr)
function uploadQrCode() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tải lên mã QR'], 403);
        return;
    }

    if (empty($_FILES['qr_code'])) {
        responseJson(['status' => 'error', 'message' => 'Không có file được tải lên'], 400);
        return;
    }

    $file = $_FILES['qr_code'];
    $file_name = basename($file['name']);
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $file_error = $file['error'];

    // Kiểm tra lỗi file
    if ($file_error !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File vượt quá giới hạn kích thước upload_max_filesize trong php.ini',
            UPLOAD_ERR_FORM_SIZE => 'File vượt quá giới hạn MAX_FILE_SIZE trong form',
            UPLOAD_ERR_PARTIAL => 'File chỉ được tải lên một phần',
            UPLOAD_ERR_NO_FILE => 'Không có file được tải lên',
            UPLOAD_ERR_NO_TMP_DIR => 'Thiếu thư mục tạm',
            UPLOAD_ERR_CANT_WRITE => 'Không thể ghi file lên đĩa',
            UPLOAD_ERR_EXTENSION => 'Phần mở rộng file không được phép'
        ];
        $message = $errors[$file_error] ?? 'Lỗi tải lên file không xác định';
        responseJson(['status' => 'error', 'message' => $message], 400);
        return;
    }

    // Kiểm tra định dạng file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowed_types)) {
        responseJson(['status' => 'error', 'message' => 'Chỉ chấp nhận file hình ảnh (jpg, png, gif)'], 400);
        return;
    }

    // Kiểm tra kích thước file (tối đa 5MB)
    if ($file_size > 5 * 1024 * 1024) {
        responseJson(['status' => 'error', 'message' => 'Kích thước file vượt quá 5MB'], 400);
        return;
    }

    // Tạo thư mục nếu chưa tồn tại
    $upload_dir = __DIR__ . '/../uploads/qr_codes/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            error_log("Failed to create directory: $upload_dir");
            responseJson(['status' => 'error', 'message' => 'Không thể tạo thư mục lưu trữ'], 500);
            return;
        }
    }

    // Kiểm tra quyền ghi thư mục
    if (!is_writable($upload_dir)) {
        error_log("Directory not writable: $upload_dir");
        responseJson(['status' => 'error', 'message' => 'Thư mục lưu trữ không có quyền ghi'], 500);
        return;
    }

    // Tạo tên file duy nhất
    $extension = pathinfo($file_name, PATHINFO_EXTENSION);
    $unique_name = uniqid() . '.' . $extension;
    $target_file = $upload_dir . $unique_name;

    // Di chuyển file đến thư mục đích
    if (move_uploaded_file($file_tmp, $target_file)) {
        // Tạo URL công khai cho file, sử dụng getBasePath()
        $base_url = rtrim(getBasePath(), '/') . '/uploads/qr_codes/';
        $file_url = $base_url . $unique_name;
        
        // Cập nhật qr_code_url trong bảng users
        try {
            $stmt = $pdo->prepare("UPDATE users SET qr_code_url = ? WHERE id = ?");
            $stmt->execute([$file_url, $user_id]);
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            // Xóa file đã tải lên nếu cập nhật DB thất bại
            unlink($target_file);
            responseJson(['status' => 'error', 'message' => 'Lỗi cập nhật cơ sở dữ liệu'], 500);
            return;
        }

        responseJson([
            'status' => 'success',
            'message' => 'Tải lên mã QR thành công',
            'data' => ['url' => $file_url]
        ]);
    } else {
        error_log("Failed to move uploaded file from $file_tmp to $target_file");
        responseJson(['status' => 'error', 'message' => 'Lỗi di chuyển file'], 500);
    }
}
?>