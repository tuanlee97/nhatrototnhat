<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getActiveContractByRoomId(PDO $pdo, int $roomId) {
    $stmt = $pdo->prepare("
        SELECT id, start_date, end_date 
        FROM contracts
        WHERE room_id = ? 
        AND status = 'active'
        AND deleted_at IS NULL
        ORDER BY start_date DESC
        LIMIT 1
    ");
    $stmt->execute([$roomId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    return $contract ?: null;
}
// Thêm người ở cùng
function createRoomOccupant() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['roomId'], $input['data']) || !is_array($input['data'])) {
        responseJson(['message' => 'Thiếu roomId hoặc data'], 400);
        return;
    }

    $roomId = $input['roomId'];
    $occupants = $input['data'];

    try {
        // Lấy hợp đồng active hiện tại
        $contract = getActiveContractByRoomId($pdo, $roomId);

        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy hợp đồng cho phòng này'], 400);
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO room_occupants (room_id, user_id, relation, start_date, end_date) VALUES (:room_id, :user_id, :relation, :start_date, :end_date)");

        foreach ($occupants as $occ) {
            if (!isset($occ['user_id'])) {
                continue; // Bỏ qua nếu thiếu user_id
            }

            $stmt->execute([
                ':room_id' => $roomId,
                ':user_id' => $occ['user_id'],
                ':relation' => $occ['relation'] ?? null,
                ':start_date' => $contract['start_date'],
                ':end_date' => $contract['end_date']
            ]);
        }

        responseJson(['status'=>'success', 'message' => 'Thêm người ở cùng thành công']);
    } catch (PDOException $e) {
        error_log("DB Error: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    } catch (Exception $e) {
        error_log("Unhandled Error: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi không xác định'], 500);
    }
}
// Lấy danh sách occupants theo room_id
function getOccupantsByRoom() {
    $pdo = getDB();  // Kết nối đến cơ sở dữ liệu
    $user = verifyJWT();  // Xác thực JWT và lấy thông tin người dùng hiện tại
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Lấy room_id từ tham số GET
    if (empty($_GET['room_id']) || !is_numeric($_GET['room_id'])) {
        responseJson(['status' => 'error', 'message' => 'room_id không hợp lệ'], 400);
        return;
    }

    $room_id = (int)$_GET['room_id'];

    // Kiểm tra quyền truy cập của người dùng
    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    try {
        // Kiểm tra sự tồn tại của phòng
        checkResourceExists($pdo, 'rooms', $room_id);

        // Truy vấn để lấy danh sách occupants của phòng
        $query = "
            SELECT ro.*, u.name AS user_name
            FROM room_occupants ro
            LEFT JOIN users u ON ro.user_id = u.id
            WHERE ro.room_id = ? and ro.deleted_at is null
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([$room_id]);

        // Lấy danh sách occupants
        $occupants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Trả về kết quả
        responseJson([
            'status' => 'success',
            'data' => $occupants
        ]);
    } catch (PDOException $e) {
        // Xử lý lỗi
        error_log("Lỗi lấy danh sách occupants cho phòng ID $room_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
// Xóa người ở cùng theo ID
function deleteRoomOccupant($occupant_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền truy cập (chỉ owner hoặc employee)
    if ($role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    // Kiểm tra occupant_id hợp lệ
    if (!is_numeric($occupant_id)) {
        responseJson(['status' => 'error', 'message' => 'occupant_id không hợp lệ'], 400);
        return;
    }

    try {
        // Kiểm tra sự tồn tại của occupant
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM room_occupants WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$occupant_id]);
        if ($stmt->fetchColumn() == 0) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy người ở cùng'], 404);
            return;
        }

        // Thực hiện soft delete
        $stmt = $pdo->prepare("UPDATE room_occupants SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$occupant_id]);

        responseJson(['status' => 'success', 'message' => 'Xóa người ở cùng thành công']);
    } catch (PDOException $e) {
        error_log("Lỗi xóa người ở cùng ID $occupant_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
// Cập nhật danh sách người ở cùng
function updateRoomOccupants() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền truy cập (chỉ owner hoặc employee)
    if ($role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['roomId'], $input['data']) || !is_array($input['data'])) {
        responseJson(['status' => 'error', 'message' => 'Thiếu roomId hoặc data'], 400);
        return;
    }

    $roomId = $input['roomId'];
    $newOccupants = $input['data'];

    try {

        // Lấy hợp đồng active hiện tại
        $contract = getActiveContractByRoomId($pdo, $roomId);

        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy hợp đồng cho phòng này'], 400);
            return;
        }


        // Bắt đầu transaction để đảm bảo tính toàn vẹn dữ liệu
        $pdo->beginTransaction();

        // Kiểm tra sự tồn tại của phòng
        checkResourceExists($pdo, 'rooms', $roomId);

        // Lấy danh sách occupants hiện tại của phòng
        $stmt = $pdo->prepare("SELECT id, user_id, relation FROM room_occupants WHERE room_id = ? AND deleted_at IS NULL");
        $stmt->execute([$roomId]);
        $currentOccupants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Tạo tập hợp user_id mới để so sánh
        $newUserIds = array_column($newOccupants, 'user_id');
        $currentUserIds = array_column($currentOccupants, 'user_id');

        // 1. Xóa (soft delete) các occupants không còn trong danh sách mới
        $occupantsToDelete = array_diff($currentUserIds, $newUserIds);
        if (!empty($occupantsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($occupantsToDelete), '?'));
            $stmt = $pdo->prepare("UPDATE room_occupants SET deleted_at = NOW() WHERE room_id = ? AND user_id IN ($placeholders)");
            $stmt->execute(array_merge([$roomId], $occupantsToDelete));
        }

        // 2. Thêm hoặc cập nhật occupants
        $insertStmt = $pdo->prepare("INSERT INTO room_occupants (room_id, user_id, relation, start_date, end_date) VALUES (:room_id, :user_id, :relation, :start_date, :end_date)");
        $updateStmt = $pdo->prepare("UPDATE room_occupants SET relation = :relation WHERE room_id = :room_id AND user_id = :user_id AND deleted_at IS NULL");

        foreach ($newOccupants as $occ) {
            if (!isset($occ['user_id'])) {
                continue; // Bỏ qua nếu thiếu user_id
            }

            $userId = $occ['user_id'];
            $relation = $occ['relation'] ?? null;
            $startDate = $contract['start_date'];
            $endDate = $contract['end_date'];
            // Kiểm tra xem occupant đã tồn tại chưa
            if (in_array($userId, $currentUserIds)) {
                // Cập nhật relation nếu cần
                $currentOccupant = array_filter($currentOccupants, fn($o) => $o['user_id'] == $userId)[array_key_first(array_filter($currentOccupants, fn($o) => $o['user_id'] == $userId))];
                if ($currentOccupant['relation'] !== $relation) {
                    $updateStmt->execute([
                        ':room_id' => $roomId,
                        ':user_id' => $userId,
                        ':relation' => $relation
                    ]);
                }
            } else {
                // Thêm occupant mới
                $insertStmt->execute([
                    ':room_id' => $roomId,
                    ':user_id' => $userId,
                    ':relation' => $relation,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate
                ]);
            }
        }

        // Commit transaction
        $pdo->commit();
        responseJson(['status' => 'success', 'message' => 'Cập nhật danh sách người ở cùng thành công']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi cập nhật danh sách occupants cho phòng ID $roomId: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Lỗi không xác định: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi không xác định'], 500);
    }
}
?>