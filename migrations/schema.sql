-- Bảng users: Lưu thông tin người dùng
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255),
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(15),
    dob DATE,
    role ENUM('admin', 'owner', 'employee', 'customer') DEFAULT 'customer',
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'inactive',
    provider ENUM('email', 'google') DEFAULT 'email',
    bank_details JSON DEFAULT NULL,
    qr_code_url VARCHAR(255) DEFAULT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_users_email (email),
    INDEX idx_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_email (email),
    INDEX idx_password_resets_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng branches: Lưu thông tin chi nhánh (nhà trọ)
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
    phone VARCHAR(15),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,  -- Trường xóa mềm
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_branches_owner_id (owner_id),
    INDEX idx_branches_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng room_types: Lưu loại phòng, liên kết với chi nhánh
CREATE TABLE IF NOT EXISTS room_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,  -- Trường xóa mềm
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_room_types_branch_id (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng rooms: Lưu thông tin phòng
CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    type_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('available', 'occupied', 'maintenance') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,  -- Trường xóa mềm
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (type_id) REFERENCES room_types(id) ON DELETE CASCADE,
    INDEX idx_rooms_branch_status (branch_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng contracts: Lưu hợp đồng thuê phòng
CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    user_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active', 'expired', 'ended', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    branch_id INT NOT NULL,
    deposit DECIMAL(10,2) DEFAULT 0.00,
    deleted_at TIMESTAMP NULL DEFAULT NULL,  -- Trường xóa mềm
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_contracts_room_status (room_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng payments: Lưu thông tin thanh toán
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    payment_date DATE,
    status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,  -- Trường xóa mềm
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    INDEX idx_payments_contract_date (contract_id, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng invoices: Lưu hóa đơn
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    branch_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,  -- Trường xóa mềm
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_invoices_contract_due_date (contract_id, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng services: Lưu dịch vụ, liên kết với chi nhánh
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    type ENUM('electricity', 'water', 'other') NOT NULL DEFAULT 'other',
    deleted_at TIMESTAMP NULL DEFAULT NULL,  -- Trường xóa mềm
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    INDEX idx_services_branch_id (branch_id),
    INDEX idx_services_is_deleted (is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng utility_usage: Lưu thông tin sử dụng dịch vụ
CREATE TABLE IF NOT EXISTS utility_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    contract_id INT NOT NULL,
    service_id INT NOT NULL,
    month VARCHAR(7) NOT NULL,
    usage_amount DECIMAL(10,2) NOT NULL,
    old_reading DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    new_reading DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    INDEX idx_utility_usage_room_contract_month (room_id, contract_id, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng maintenance_requests: Lưu yêu cầu bảo trì
CREATE TABLE IF NOT EXISTS maintenance_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    room_id INT NOT NULL,
    description TEXT NOT NULL,
    status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME,
    deleted_at DATETIME,
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng notifications: Lưu thông báo
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,  -- Trường xóa mềm
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng tickets: Lưu yêu cầu hỗ trợ
CREATE TABLE IF NOT EXISTS tickets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    room_id INT,
    contract_id INT,
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
    created_at DATETIME NOT NULL,
    updated_at DATETIME,
    resolved_at DATETIME,
    deleted_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (contract_id) REFERENCES contracts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Bảng room_occupants: Lưu thông tin người ở trong phòng
CREATE TABLE IF NOT EXISTS room_occupants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    user_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    relation VARCHAR(255),
    deleted_at TIMESTAMP NULL DEFAULT NULL,  -- Trường xóa mềm
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_room_occupants_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng employee_assignments: Lưu phân công nhân viên cho chi nhánh
CREATE TABLE IF NOT EXISTS employee_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    branch_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_assignment (employee_id, branch_id),
    INDEX idx_employee_assignments_employee_id (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng branch_customers: Lưu thông tin khách hàng của chi nhánh
CREATE TABLE IF NOT EXISTS branch_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_branch_user (branch_id, user_id),
    INDEX idx_branch_customers_branch_id (branch_id),
    INDEX idx_branch_customers_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bảng token_blacklist: Lưu trữ các token JWT bị thu hồi
CREATE TABLE IF NOT EXISTS token_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token TEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_blacklist_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Bảng logs: Lưu trữ log hệ thống
CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stored Procedure để tự động kết thúc hợp đồng

CREATE PROCEDURE AutoEndContracts()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_contract_id INT;
    DECLARE v_room_id INT;
    DECLARE v_user_id INT;
    DECLARE v_branch_id INT;
    DECLARE v_deposit DECIMAL(10,2);
    DECLARE v_room_price DECIMAL(10,2);
    DECLARE v_start_date DATE;
    DECLARE v_status VARCHAR(20);
    DECLARE v_current_date DATE;
    DECLARE v_current_month VARCHAR(7);
    DECLARE v_days_in_month INT;
    DECLARE v_usage_days INT;
    DECLARE v_usage_ratio DECIMAL(5,2);
    DECLARE v_amount_due DECIMAL(10,2);
    DECLARE v_invoice_id INT;

    -- Cursor để lấy danh sách hợp đồng active có end_date nhỏ hơn hoặc bằng ngày hiện tại
    DECLARE contract_cursor CURSOR FOR
        SELECT c.id, c.room_id, c.user_id, c.branch_id, c.deposit, r.price, c.start_date, c.status
        FROM contracts c
        JOIN rooms r ON c.room_id = r.id
        WHERE c.status = 'active'
        AND c.end_date <= CURDATE()
        AND c.deleted_at IS NULL;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    -- Lấy ngày hiện tại và tháng hiện tại
    SET v_current_date = CURDATE();
    SET v_current_month = DATE_FORMAT(v_current_date, '%Y-%m');

    -- Bắt đầu giao dịch
    START TRANSACTION;

    -- Mở cursor
    OPEN contract_cursor;

    contract_loop: LOOP
        FETCH contract_cursor INTO v_contract_id, v_room_id, v_user_id, v_branch_id, v_deposit, v_room_price, v_start_date, v_status;
        IF done THEN
            LEAVE contract_loop;
        END IF;

        -- Kiểm tra trạng thái hợp đồng
        IF v_status != 'active' THEN
            UPDATE contracts SET deleted_at = NOW() WHERE id = v_contract_id;
            INSERT INTO logs (message, created_at)
            VALUES (CONCAT('Hợp đồng ID ', v_contract_id, ' đã bị xóa mềm vì không ở trạng thái active.'), NOW());
            ITERATE contract_loop;
        END IF;

        -- Tính số ngày trong tháng
        SET v_days_in_month = DAY(LAST_DAY(v_current_date));

        -- Tính số ngày sử dụng
        IF DATE_FORMAT(v_start_date, '%Y-%m') = v_current_month THEN
            SET v_usage_days = GREATEST(1, DATEDIFF(v_current_date, v_start_date) + 1);
        ELSE
            SET v_usage_days = DAY(v_current_date);
        END IF;

        SET v_usage_ratio = v_usage_days / v_days_in_month;


        -- Kiểm tra utility_usage
        SET @utility_count = (
            SELECT COUNT(*)
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.room_id = v_room_id
            AND u.month = v_current_month
            AND u.contract_id = v_contract_id
            AND u.recorded_at >= v_start_date
            AND u.recorded_at <= v_current_date
            AND u.deleted_at IS NULL
        );
        IF @utility_count = 0 THEN
        -- Tạo bản ghi utility_usage mặc định nếu không có
            INSERT INTO utility_usage (
                room_id, contract_id, service_id, usage_amount, month, recorded_at, old_reading, new_reading
            )
            SELECT
                v_room_id,
                v_contract_id,
                s.id,
                0,
                v_current_month,
                NOW(),
                IFNULL((
                    SELECT u.new_reading
                    FROM utility_usage u
                    WHERE u.room_id = v_room_id AND u.service_id = s.id
                    AND u.recorded_at < NOW()
                    AND u.deleted_at IS NULL
                    ORDER BY u.recorded_at DESC
                    LIMIT 1
                ), 0),
                IFNULL((
                    SELECT u.new_reading
                    FROM utility_usage u
                    WHERE u.room_id = v_room_id AND u.service_id = s.id
                    AND u.recorded_at < NOW()
                    AND u.deleted_at IS NULL
                    ORDER BY u.recorded_at DESC
                    LIMIT 1
                ), 0)
            FROM services s
            WHERE s.type IN ('electricity', 'water')
              AND s.branch_id = v_branch_id
              AND s.deleted_at IS NULL;

            -- Ghi log
            INSERT INTO logs (message, created_at)
            VALUES (CONCAT('Tạo utility_usage mặc định cho hợp đồng ', v_contract_id, ' với chỉ số = 0 hoặc số gần nhất.'), NOW());
        END IF;

        -- Tính tổng chi phí tiện ích
        SET v_amount_due = (
            SELECT ROUND(SUM(u.usage_amount * s.price))
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.room_id = v_room_id
            AND u.month = v_current_month
            AND u.contract_id = v_contract_id
            AND u.recorded_at >= v_start_date
            AND u.recorded_at <= v_current_date
            AND u.deleted_at IS NULL
        );

        -- Nếu không có chi phí tiện ích, gán giá trị mặc định
        IF v_amount_due IS NULL THEN
            SET v_amount_due = 0;
        END IF;

        -- Thêm tiền phòng
        SET v_amount_due = v_amount_due + ROUND(v_room_price * v_usage_ratio);

        -- Cập nhật trạng thái hợp đồng
        UPDATE contracts
        SET status = 'expired', end_date = NOW()
        WHERE id = v_contract_id;

        -- Cập nhật trạng thái phòng
        UPDATE rooms
        SET status = 'available'
        WHERE id = v_room_id;

        -- Tạo hóa đơn
        INSERT INTO invoices (contract_id, branch_id, amount, due_date, status, created_at)
        VALUES (v_contract_id, v_branch_id, v_amount_due, v_current_date, 'pending', NOW());

        -- Lấy ID hóa đơn vừa tạo
        SET v_invoice_id = LAST_INSERT_ID();

        -- Tạo bản ghi thanh toán
        INSERT INTO payments (contract_id, amount, due_date, status, created_at)
        VALUES (v_contract_id, v_amount_due, v_current_date, 'pending', NOW());

        -- Gửi thông báo
        IF v_deposit > 0 THEN
            INSERT INTO notifications (user_id, message, created_at)
            VALUES (v_user_id, CONCAT('Tiền đặt cọc ', v_deposit, ' cho hợp đồng ID ', v_contract_id, ' đã được hoàn.'), NOW());
        END IF;

        INSERT INTO notifications (user_id, message, created_at)
        VALUES (v_user_id, CONCAT('Hợp đồng ID ', v_contract_id, ' đã tự động kết thúc. Hóa đơn ID ', v_invoice_id, ' đã được tạo cho ', v_usage_days, '/', v_days_in_month, ' ngày sử dụng trong tháng ', v_current_month, '.'), NOW());

        -- Ghi log
        INSERT INTO logs (message, created_at)
        VALUES (CONCAT('Hợp đồng ID ', v_contract_id, ' đã được kết thúc tự động và tạo hóa đơn ID ', v_invoice_id), NOW());
    END LOOP;

    CLOSE contract_cursor;
    COMMIT;
END;


SET GLOBAL event_scheduler = ON;

CREATE EVENT auto_end_contracts_event
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
    CALL AutoEndContracts();
END;