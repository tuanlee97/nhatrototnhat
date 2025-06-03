# Tài liệu dự án Quản lý Nhà trọ

## Giới thiệu

Đây là hệ thống quản lý nhà trọ/phòng trọ với backend PHP, cung cấp API RESTful cho các chức năng quản lý người dùng, phòng, hợp đồng, thanh toán, dịch vụ, bảo trì, thông báo, báo cáo, v.v.

## Cấu trúc thư mục chính

- `index.php`: Điểm vào của ứng dụng.
- `api/`: Chứa các file API cho từng chức năng.
- `core/`: Chứa các file lõi như router, database, helpers.
- `config/`: Cấu hình ứng dụng và database.
- `uploads/`: Thư mục lưu trữ file upload (QR code, ...).
- `logs/`: Log hệ thống và API.
- `migrations/`: File SQL khởi tạo CSDL.
- `vendor/`: Thư viện bên thứ ba (ví dụ: PHPMailer).
- `cache/`: Lưu file rate limit, cache tạm.

## Quyền thư mục cần thiết

Để hệ thống hoạt động ổn định, cần phân quyền ghi cho các thư mục sau:

- `config/`, `logs/`, `cache/`: Cần quyền ghi (chmod 775 hoặc 777 tuỳ môi trường)
- `uploads/`, đặc biệt là `uploads/qr_codes/`: Cần quyền ghi cho web server

Ví dụ lệnh phân quyền:

```bash
sudo chmod -R 775 logs
sudo chmod -R 775 config
sudo chown -R www-data:www-data cache
sudo chown -R www-data:www-data uploads/
sudo chmod -R 775 uploads/qr_codes
```

## Các file cấu hình/cơ sở dữ liệu cần có

- `cache/rate_limit.json`: Dùng để lưu thông tin giới hạn truy cập (rate limit) cho API. Nếu chưa có, hãy tạo file rỗng `{}`.
- `config/app.php`: File cấu hình ứng dụng. Nếu chưa có, hãy copy từ `app.php.example` và chỉnh sửa phù hợp.

## Quy trình cài đặt lần đầu & cấu hình hệ thống

Khi truy cập lần đầu, hệ thống sẽ tự động chuyển hướng tới `/install` để tiến hành cài đặt:

1. **Bước 1: Nhập thông tin kết nối database**

   - Bạn cần cung cấp các thông tin:
     - DB Host
     - DB Name
     - DB Port
     - DB Username
     - DB Password
   - Hệ thống sẽ kiểm tra kết nối. Nếu thành công sẽ chuyển sang bước tiếp theo.

2. **Bước 2: Tạo tài khoản admin đầu tiên**

   - Nhập thông tin tài khoản quản trị viên.

3. **Hoàn tất cài đặt**

   - Hệ thống sẽ tự động tạo file `config/installed.php` để đánh dấu đã cài đặt.
   - Tạo file `config/database.php` để lưu thông tin truy cập database.
   - Lần sau truy cập sẽ không chạy lại bước này.

4. **Cấu hình ứng dụng (`config/app.php`)**
   - Lưu các thông tin cấu hình khác như:
     ```php
     <?php
     return array(
         'YOUR_GOOGLE_CLIENT_ID' => 'YOUR_GOOGLE_CLIENT_ID',
         'SECRET_KEY' => 'SECRET_KEY',
         // Cấu hình SMTP cho PHPMailer
         'SMTP_HOST' => 'smtp.gmail.com', // Server SMTP (ví dụ: Gmail)
         'SMTP_PORT' => 587, // Cổng SMTP
         'SMTP_ENCRYPTION' => 'tls', // Loại mã hóa (tls hoặc ssl)
         'SMTP_USERNAME' => 'your-email@gmail.com', // Email gửi
         'SMTP_PASSWORD' => 'your-app-password', // App Password của Gmail
         'SMTP_FROM_EMAIL' => 'no-reply@yourdomain.com', // Email "From"
         'SMTP_FROM_NAME' => 'Your App', // Tên "From"
         // URL frontend
         'FRONTEND_URL' => 'http://your-frontend-domain', // URL của frontend
     );
     ?>
     ```
   - Bạn có thể chỉnh sửa file này để thay đổi các thông tin liên quan đến Google, SMTP, hoặc frontend URL.

## Cây thư mục dự án (rút gọn)

```
.
├── index.php
├── README.md
├── api/
│   ├── ...
├── cache/
│   ├── rate_limit.json
├── config/
│   ├── app.php
├── core/
│   ├── ...
├── logs/
│   ├── ...
├── migrations/
│   └── schema.sql
├── uploads/
│   └── qr_codes/
└── vendor/
    └── ...
```

## Danh sách đầy đủ các API

- `auth.php`: Xác thực
- `users.php`: Quản lý người dùng
- `employees.php`: Quản lý nhân viên
- `branches.php`: Quản lý chi nhánh
- `rooms.php`: Quản lý phòng
- `room_types.php`: Quản lý loại phòng
- `contracts.php`: Quản lý hợp đồng
- `payments.php`: Quản lý thanh toán
- `invoices.php`: Quản lý hóa đơn
- `utility_usage.php`: Ghi nhận số điện nước
- `maintenance_requests.php`: Yêu cầu bảo trì
- `notifications.php`: Thông báo hệ thống
- `reports.php`: Báo cáo tổng hợp
- `services.php`: Quản lý dịch vụ
- `branch_service_defaults.php`: Giá dịch vụ mặc định theo chi nhánh
- `settings.php`: Cấu hình hệ thống
- `room_occupants.php`: Quản lý người ở phòng
- `employee_assignments.php`: Phân công nhân viên
- `branch_customers.php`: Quản lý khách hàng chi nhánh
- `room_price_history.php`: Lịch sử giá phòng
- `room_status_history.php`: Lịch sử trạng thái phòng
- `tickets.php`: Quản lý yêu cầu hỗ trợ
- `reviews.php`: Quản lý đánh giá
- `promotions.php`: Quản lý khuyến mãi
- `upload.php`: Upload file (QR code, ...)
- `utils/common.php`, `utils/config.php`: Tiện ích nội bộ

## Hướng dẫn cài đặt

1. Copy file cấu hình mẫu:
   ```bash
   cp config/app.php.example config/app.php
   cp config/database.php.example config/database.php
   ```
2. Chỉnh sửa thông tin kết nối DB trong `config/database.php`.
3. Truy cập trình duyệt vào domain để tiến hành cài đặt qua giao diện (`/install`).

## Sử dụng API

- Tất cả API đều nằm dưới `/api/`.
- Sử dụng JWT cho các API cần xác thực.
- Tham khảo chi tiết các endpoint, tham số, ví dụ request/response trong file `api/README.md`.
