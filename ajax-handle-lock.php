<?php
session_start();
require "config.php";
header('Content-Type: application/json');



if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Lỗi kết nối']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit;
}

$action = $_POST['action'] ?? '';
$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
$machine_idx = isset($_POST['machine_idx']) ? (int) $_POST['machine_idx'] : 0;
$config_name = isset($_POST['config_name']) ? mb_strtolower(trim($_POST['config_name']), 'UTF-8') : '';

if ($action === 'check') {
    // 1. Kiểm tra xem có ai KHÁC đang làm máy này không (đang active lock)
    // So sánh config_name trong PHP để tránh lỗi NFC/NFD tiếng Việt trong SQL
    $stmt = $pdo->prepare("SELECT t.user_id, t.config_name, u.fullname FROM trang_thai_lap_may t 
                           LEFT JOIN users u ON t.user_id = u.id
                           WHERE t.id_donhang = ? AND t.so_may = ? AND t.user_id IS NOT NULL AND t.user_id != ?");
    $stmt->execute([$order_id, $machine_idx, $user_id]);
    $someone_else = null;
    $clean_req_cfg = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($config_name), 'UTF-8'));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $clean_db_cfg = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($row['config_name']), 'UTF-8'));
        if ($clean_db_cfg === $clean_req_cfg) {
            $someone_else = $row;
            break;
        }
    }

    if ($someone_else) {
        $locker_name = $someone_else['fullname'] ?: "ID " . $someone_else['user_id'];
        echo json_encode([
            'success' => true,
            'status' => 'locked',
            'message' => "Máy này đang được {$locker_name} xử lý. Vui lòng chọn máy khác!"
        ]);
        exit;
    }
    // 2. Lấy tất cả các máy mà user này đang "dính" vào (cả session và data nháp)
    $all_activity = [];

    // A. Từ bảng session
    $stmt = $pdo->prepare("SELECT id_donhang, so_may, config_name, 'session' as source FROM trang_thai_lap_may WHERE user_id = ?");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $all_activity[] = $r;
    }

    // B. Từ bảng chi tiết (chưa lưu) - Chỉ tính là "đang làm dở" nếu đã scan ít nhất 1 serial
    $stmt_work = $pdo->prepare("SELECT id_donhang, so_may, linhkien_chon as config_name, 'draft' as source 
                                FROM chitiet_donhang 
                                WHERE user_id = ? AND user_id_save IS NULL AND so_serial != '' 
                                AND linhkien_chon IS NOT NULL AND linhkien_chon != '' AND so_may > 0");
    $stmt_work->execute([$user_id]);
    foreach ($stmt_work->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Chuẩn hóa config_name
        if (strpos($r['config_name'], '|') !== false) {
            $parts = explode('|', $r['config_name']);
            $r['config_name'] = trim($parts[0]);
        }
        $all_activity[] = $r;
    }


    $other_machine = null;
    $is_already_on_this = false;
    foreach ($all_activity as $act) {
        // Bỏ qua hoàn toàn các hoạt động thuộc đơn hàng KHÁC.
        // User được phép tự do chuyển qua lại giữa các đơn hàng khác nhau.
        if ((int) $act['id_donhang'] !== (int) $order_id) {
            // Xóa lock rác từ đơn hàng cũ nếu là session lock
            if (($act['source'] ?? '') === 'session') {
                $pdo->prepare("DELETE FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ? AND config_name = ? AND user_id = ?")
                    ->execute([$act['id_donhang'], $act['so_may'], $act['config_name'], $user_id]);
            }
            continue;
        }

        // Làm sạch để so sánh tên cấu hình (tránh lỗi NFC/NFD tiếng Việt)
        $clean_act = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($act['config_name']), 'UTF-8'));
        $clean_req = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($config_name), 'UTF-8'));

        $is_same = ((int) $act['so_may'] == $machine_idx && $clean_act === $clean_req);
        if ($is_same) {
            $is_already_on_this = true;
        } else {
            $other_machine = $act;
            break; // Tìm thấy máy khác trong CÙNG đơn hàng đang làm dở
        }
    }
    if ($other_machine) {
        $msg = "Đơn hàng " . $other_machine['id_donhang'] .
            " - " . $other_machine['config_name'] .
            " - Máy số " . $other_machine['so_may'] .
            " chưa hoàn thành. Bạn có muốn tạm dừng để chuyển sang máy số " .
            $machine_idx . " không?";
        echo json_encode([
            'success' => true,
            'status' => 'busy',
            'message' => $msg,
            'other' => $other_machine
        ]);
        exit;
    }
    if ($is_already_on_this) {
        echo json_encode(['success' => true, 'status' => 'my_lock']);
        exit;
    }
    // 3. Nếu mình không bận, kiểm tra xem máy này đã HOÀN THIỆN chưa
    // Lấy tất cả linh kiện của đơn hàng để tính toán số lượng máy thực tế (như kho-hang.php)
    $stmt_all = $pdo->prepare("SELECT loai_linhkien, ten_cauhinh FROM chitiet_donhang WHERE id_donhang = ?");
    $stmt_all->execute([$order_id]);
    $all_rows = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

    $temp_all_configs = [];
    foreach ($all_rows as $row) {
        $trimmed = rtrim($row['ten_cauhinh'], ' ');
        $spaces = strlen($row['ten_cauhinh']) - strlen($trimmed);
        $parts = array_map('trim', explode(',', $trimmed));
        $owner = mb_strtolower($parts[$spaces] ?? $parts[0], 'UTF-8');
        $temp_all_configs[$owner][] = $row;
    }

    $expected_count = 0;
    foreach ($temp_all_configs as $k => $items) {
        if (mb_strtolower($k, 'UTF-8') === mb_strtolower($config_name, 'UTF-8')) {
            $preferred = ['cpu', 'main', 'mainboard', 'vga', 'ssd', 'psu', 'fan', 'case','win'];
            $type_counts = array_count_values(array_map('mb_strtolower', array_column($items, 'loai_linhkien')));
            $qty = 0;
            foreach ($preferred as $t) {
                if (!empty($type_counts[$t])) {
                    $qty = (int) $type_counts[$t];
                    break;
                }
            }
            $qty = $qty > 0 ? $qty : 1;
            // Tính số linh kiện trung bình mỗi máy cho cấu hình này
            $expected_count = (int) ceil(count($items) / $qty);
            break;
        }
    }
    $stmt_done = $pdo->prepare("SELECT COUNT(*) as current_done
                                FROM chitiet_donhang
                                WHERE id_donhang = ? AND linhkien_chon = ? AND so_may = ?
                                AND user_id_save IS NOT NULL AND user_id_save != 0 AND so_serial != ''");
    $stmt_done->execute([$order_id, $config_name, $machine_idx]);
    $done_row = $stmt_done->fetch(PDO::FETCH_ASSOC);
    $current_done = (int) ($done_row['current_done'] ?? 0);
    if ($expected_count > 0 && $current_done >= $expected_count) {
        // Kiểm tra xem máy này có linh kiện RAM không (để cho phép sửa serial RAM)
        $stmt_ram = $pdo->prepare("SELECT COUNT(*) as ram_count FROM chitiet_donhang
                                   WHERE id_donhang = ? AND linhkien_chon = ? AND so_may = ?
                                   AND LOWER(TRIM(loai_linhkien)) = 'ram'");
        $stmt_ram->execute([$order_id, $config_name, $machine_idx]);
        $ram_row = $stmt_ram->fetch(PDO::FETCH_ASSOC);
        $has_ram = ($ram_row && (int) $ram_row['ram_count'] > 0);

        echo json_encode([
            'success' => true,
            'status' => 'done',
            'has_ram' => $has_ram,
            'message' => 'Máy này đã hoàn thiện! Vui lòng chọn máy khác.'
        ]);
        exit;
    }
    echo json_encode(['success' => true, 'status' => 'available']);
} elseif ($action === 'lock') {
    $force = isset($_POST['force']) && $_POST['force'] == '1';

    if ($force) {
        // Kiểm tra xem máy cũ của họ là máy nào trước khi xóa để quyết định có giữ Draft không
        $stmt_old = $pdo->prepare("SELECT id_donhang, so_may, config_name FROM trang_thai_lap_may WHERE user_id = ? LIMIT 1");
        $stmt_old->execute([$user_id]);
        $old_machine = $stmt_old->fetch(PDO::FETCH_ASSOC);

        $is_reentering_same = false;
        if ($old_machine) {
            $is_reentering_same = ($old_machine['id_donhang'] == $order_id &&
                (int) $old_machine['so_may'] == $machine_idx &&
                mb_strtolower(trim($old_machine['config_name']), 'UTF-8') === $config_name);
        }

        // CHỈNH SỬA: Không xóa global nữa để tránh mất dấu khi người dùng làm nhiều đơn hàng cùng lúc
        // Chỉ cần để logic INSERT/UPDATE bên dưới xử lý việc chiếm máy hiện tại là đủ.
        if ($is_reentering_same) {
            $GLOBALS['STAY_DRAFT'] = true;
        }
    }

    // Kiểm tra chéo một lần nữa trước khi thực hiện khóa (Phòng trường hợp 2 người cùng nhấn)
    $stmt_occupied = $pdo->prepare("SELECT user_id FROM trang_thai_lap_may 
                                    WHERE id_donhang = ? AND so_may = ? AND config_name = ? AND user_id IS NOT NULL AND user_id != ?
                                    LIMIT 1");
    $stmt_occupied->execute([$order_id, $machine_idx, $config_name, $user_id]);
    $occupied = $stmt_occupied->fetch();


    if ($occupied) {
        echo json_encode([
            'success' => false,
            'message' => 'Rất tiếc, máy đã được sử dụng bởi người dùng khác '
        ]);
        exit;
    }
    // Kiểm tra xem đã có khóa của chính user này chưa
    $stmt_check = $pdo->prepare("SELECT id FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ? AND config_name = ? AND user_id = ?");
    $stmt_check->execute([$order_id, $machine_idx, $config_name, $user_id]);
    if (!$stmt_check->fetch() && !isset($GLOBALS['STAY_DRAFT'])) {
        $m_key = "{$order_id}_{$machine_idx}_{$config_name}";
        $_SESSION['FRESH_LOCK_' . $m_key] = true;
    }

    // Chỉ cập nhật hoặc chèn nếu KHÔNG ai chiếm (hoặc chính mình chiếm)
    $stmt = $pdo->prepare("INSERT INTO trang_thai_lap_may (id_donhang, so_may, config_name, user_id) 
                           VALUES (?, ?, ?, ?) 
                           ON DUPLICATE KEY UPDATE user_id = ?");
    $stmt->execute([$order_id, $machine_idx, $config_name, $user_id, $user_id]);

    // Cấp Token cho phép vào máy (Phòng chống copy URL)
    $_SESSION['ENTRY_TOKEN'] = "{$order_id}_{$machine_idx}_{$config_name}";

    echo json_encode(['success' => true, 'message' => 'Đã khóa máy']);
} elseif ($action === 'unlock') {
    $stmt = $pdo->prepare("DELETE FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ? AND config_name = ? AND user_id = ?");
    $stmt->execute([$order_id, $machine_idx, $config_name, $user_id]);
    echo json_encode(['success' => true, 'message' => 'Đã mở khóa máy']);
}
