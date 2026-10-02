<?php
ob_start();
require "config.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input || empty($input['id_donhang']) || empty($input['so_serial'])) {
        echo json_encode(["status" => "error", "message" => "Thiếu thông tin đơn hàng hoặc serial"]);
        exit;
    }

    $id_donhang = (int) $input['id_donhang'];
    $so_serial = trim($input['so_serial']);
    $ten_linhkien = isset($input['ten_linhkien']) ? trim($input['ten_linhkien']) : '';
    $loai_linhkien = isset($input['loai_linhkien']) ? trim($input['loai_linhkien']) : '';
    $config_name = isset($input['config_name']) ? trim($input['config_name']) : '';

    try {
        // Tinh chỉnh Database: Tự động thêm id_ct nếu thiếu
        try {
            $check = $pdo->query("SHOW COLUMNS FROM chitiet_donhang LIKE 'id_ct'");
            if ($check->rowCount() == 0) {
                $pdo->exec("ALTER TABLE chitiet_donhang ADD id_ct INT AUTO_INCREMENT PRIMARY KEY FIRST");
            }
        } catch (Exception $e) {
        }

        // ĐẶC BIỆT CHO PHẦN MỀM (WIN) VÀ CASE:
        if (strtoupper($loai_linhkien) === 'WIN' || strtoupper($loai_linhkien) ==='CASE') {
            $id_ct_dang_nhap = isset($input['id_ct']) ? (int) $input['id_ct'] : 0;
            $id_ct_dang_nhap = isset($input['id_ct']) ? (int) $input['id_ct'] : 0;
            $ten_loai_display = (strtoupper($loai_linhkien) === 'WIN') ? 'WIN' : 'CASE';
            $ten_tieng_viet = (strtoupper($loai_linhkien) === 'WIN') ? 'Phần mềm' : 'Vỏ máy';

            if ($so_serial === '') {
                echo json_encode(["status" => "match", "message" => "✓ Hợp lệ (Không bắt buộc)", "id_ct" => $id_ct_dang_nhap]);
                exit;
            }

            // Cho phép nhập trùng serial cho WIN và CASE - không kiểm tra trùng lặp
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
            if ($session_user_id > 0 && $id_ct_dang_nhap > 0) {
                $stmt_update_user = $pdo->prepare("UPDATE chitiet_donhang SET user_id = ? WHERE id_ct = ?");
                $stmt_update_user->execute([$session_user_id, $id_ct_dang_nhap]);
            }

            echo json_encode([
                "status" => "match",
                "message" => "✓ Serial hợp lệ (Phần mềm)",
                "available_count" => 1,
                "id_ct" => $id_ct_dang_nhap
            ]);
            exit;
        }

        // [MỚI] ĐẶC BIỆT CHO IMEI: Bỏ qua kiểm tra
        if (strtoupper($loai_linhkien) === 'IMEI' || strtoupper($loai_linhkien) === 'IMER') {
            echo json_encode([
                "status" => "match",
                "message" => "✓ Hợp lệ (Không kiểm tra)",
                "id_ct" => isset($input['id_ct']) ? (int) $input['id_ct'] : 0
            ]);
            exit;
        }

        // Bước 1: Lấy linh kiện tương tự (để kiểm tra xem có dòng nào mang cùng tên/loại không)
        $stmt = $pdo->prepare(
            "SELECT * FROM chitiet_donhang 
             WHERE id_donhang = ? AND LOWER(ten_linhkien) = LOWER(?) AND LOWER(loai_linhkien) = LOWER(?)"
        );
        $stmt->execute([$id_donhang, $ten_linhkien, $loai_linhkien]);
        // $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Bước 2: Đếm xem còn bao nhiêu dòng có số Serial này mà ĐANG TRỐNG (chưa gán máy nào)
        // Hoặc đã gán cho đúng máy hiện tại. Dùng LOWER trong SQL để không lỗi function PHP.
        $stmt_check_avail = $pdo->prepare(
            "SELECT id_ct, linhkien_chon, so_may FROM chitiet_donhang 
             WHERE id_donhang = ? AND so_serial = ? AND LOWER(loai_linhkien) = LOWER(?) AND LOWER(ten_linhkien) = LOWER(?)"
        );
        $stmt_check_avail->execute([$id_donhang, $so_serial, $loai_linhkien, $ten_linhkien]);
        $all_matches = $stmt_check_avail->fetchAll(PDO::FETCH_ASSOC);

        if (empty($all_matches)) {
            $ten_may_hien_tai = '';
            if (strpos($config_name, '|') !== false) {
                $parts = explode('|', $config_name);
                $ten_may_hien_tai = trim(end($parts));
            } else {
                $ten_may_hien_tai = empty($config_name) ? 'máy này' : $config_name;
            }
            echo json_encode([
                "status" => "no_match",
                "message" => "❌ Không tìm thấy Serial {$so_serial} linh kiện {$loai_linhkien} thuộc {$ten_may_hien_tai} "

            ]);
            exit;
        }

        // Lấy ID của dòng linh kiện hiện tại đang thao tác trên giao diện
        $id_ct_dang_nhap = isset($input['id_ct']) ? (int) $input['id_ct'] : 0;

        $available_rows = [];
        foreach ($all_matches as $m) {
            $assigned_cfg = trim((string) ($m['linhkien_chon'] ?? ''));
            $assigned_id_ct = (int) ($m['id_ct'] ?? 0);

            // CHÍNH XÁC TUYỆT ĐỐI:
            // 1. Nếu dòng linh kiện này trong kho chưa gán cho máy nào ($assigned_cfg trống) => HỢP LỆ
            if ($assigned_cfg === '') {
                $available_rows[] = $m;
                continue;
            }
            // 2. Nếu đã gán, nhưng Record ID ($assigned_id_ct) trùng khít với ID đang nhập => HỢP LỆ
            // (Điều này cho phép bạn Enter hoặc quét lại chính mã Serial đã lưu cho đúng ô đó)
            if ($id_ct_dang_nhap > 0 && $assigned_id_ct === $id_ct_dang_nhap) {
                $available_rows[] = $m;
                continue;
            }
        }
        if (empty($available_rows)) {
            // Lấy đại diện 1 cái để báo lỗi xem nó đang ở đâu
            $first_busy = $all_matches[0];


            $ten_may_chiem = '';
            $cfg = !empty($first_busy['linhkien_chon']) ? $first_busy['linhkien_chon'] : $first_busy['ten_cauhinh'];
            $sm = $first_busy['so_may'];

            if (!empty($cfg) && !empty($sm)) {
                // Tránh lặp lại "Máy X | Máy X" nếu $cfg đã chứa "Máy"
                if (strpos($cfg, '|') !== false) {
                    $ten_may_chiem = $cfg;
                } else {
                    $ten_may_chiem = $cfg . " | Máy " . $sm;
                }
            } elseif (!empty($sm)) {
                $ten_may_chiem = "Máy " . $sm;
            } elseif (!empty($cfg)) {
                $ten_may_chiem = $cfg;
            } else {
                $ten_may_chiem = "Máy khác";
            }
            echo json_encode([
                "status" => "error",
                "message" => "⚠️ Serial đã trùng với {$ten_may_chiem}"
            ]);
            exit;
        }

        // Thành công: Trả về dòng đầu tiên rảnh + Tổng số lượng rảnh có Serial này
        $machine_label = "";

        if (!empty($config_name)) {
            $machine_label = $config_name;

            if (isset($input['machine_idx'])) {
                $machine_label .= " | Máy " . $input['machine_idx'];
            }
        }
        $msg = "✓ Serial hợp lệ ";
        if (!empty($machine_label)) {
            $msg .= " " . $machine_label;
        }
        $target_id_ct = $available_rows[0]['id_ct'];
        // --- CẬP NHẬT USER_ID NGAY LÚC NHẬP (MỚI) ---
        session_start();
        $session_user_id = (int) ($_SESSION['user_id'] ?? 0);
        if ($session_user_id > 0) {
            $stmt_update_user = $pdo->prepare("UPDATE chitiet_donhang SET user_id = ? WHERE id_ct = ?");
            $stmt_update_user->execute([$session_user_id, $target_id_ct]);
        }

        echo json_encode([
            "status" => "match",
            "message" => $msg,
            "available_count" => count($available_rows),
            "id_ct" => $target_id_ct
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Lỗi database: " . $e->getMessage()]);
    }
    exit;
}
